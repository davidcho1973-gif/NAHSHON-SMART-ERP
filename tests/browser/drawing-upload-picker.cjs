// Synthetic documents only; checks fresh selection and uploads while PDF reading is busy.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '@playwright/test');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  try {
    const page = await browser.newPage();
    await page.route('https://synthetic.test/**', r => r.fulfill({ contentType: 'text/html', body: '<html></html>' }));
    await page.goto('https://synthetic.test/');
    await page.addScriptTag({ path: 'public/js/admin-shell.js' });
    await page.addScriptTag({ path: 'public/js/admin-section-drawings.js' });
    await page.evaluate(() => {
      window._currentView = 'other'; // Do not trigger automatic reading unrelated fixture documents.
      const board = { siteId: 1, documents: [], catalog: [], sections: [{ id: 2, code: 'A', name: 'Walls', sheets: [] }] };
      AdminSectionDrawings._state.data = structuredClone(board);
      board.catalog = [{ id: 5, sheetNo: 'NEW-A1', title: 'Uploaded plan', pageNo: 1 }];
      window.gsRun = async () => structuredClone(board);
      window.started = [];
      window.releaseFirst = null;
      AdminSectionDrawings._state.pdfjs = { getDocument({ url }) {
        return { promise: url === '/first' ? new Promise(resolve => { window.releaseFirst = () => resolve({ numPages: 1 }); }) : Promise.resolve({ numPages: 1 }), destroy() {} };
      }};
      window.fetch = async (url, options) => {
        let data;
        if (url === '/document-hub/api/upload') {
          if (options.body.get('purpose') !== 'drawing') throw Error('Drawing purpose missing');
          const second = options.body.get('files[]').name === 'second.pdf';
          data = second ? { success: true, duplicates: [{ drawing: { id: 12, title: 'Old duplicate', pdfUrl: '/second' } }] }
            : { success: true, documents: [{ id: 11, title: 'First', pdfUrl: '/first' }] };
        } else { started.push(url); data = { success: true, sheets: [] }; }
        return { ok: true, status: 200, json: async () => data };
      };
    });
    await page.evaluate(() => AdminSectionDrawings.pick(2));
    assert.equal(await page.locator('input[value="NEW-A1"]').count(), 1);
    await page.getByRole('button', { name: '취소', exact: true }).click();
    await page.evaluate(() => AdminSectionDrawings.upload({ files: [new File(['pdf'], 'first.pdf')], value: '' }));
    await page.waitForFunction(() => typeof releaseFirst === 'function');
    await page.evaluate(() => AdminSectionDrawings.upload({ files: [new File(['pdf'], 'second.pdf')], value: '' }));
    await page.waitForTimeout(100);
    await page.evaluate(() => releaseFirst());
    await page.waitForFunction(() => started.length === 2);
    assert.deepEqual(await page.evaluate(() => started), ['/drawing-api/documents/11/start', '/drawing-api/documents/12/start']);
    console.log('PASS: fresh picker, explicit drawing upload, duplicate reference and queued upload');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
