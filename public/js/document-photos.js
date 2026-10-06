(function (root) {
  'use strict';
  const isPhoto = f => /^image\//.test(f.type);
  // A document used to mean one file, so each invoice page became a separate record.
  // Embed reduced JPEGs as ordered PDF pages; existing preview and AI see one document.
  function pdf(pages) {
    const encoder = new TextEncoder(), chunks = [], offsets = [0]; let length = 0;
    const write = value => { const bytes = typeof value === 'string' ? encoder.encode(value) : value; chunks.push(bytes); length += bytes.length; };
    const object = (id, body, bytes) => { offsets[id] = length; write(id + ' 0 obj\n' + body); if (bytes) { write('\nstream\n'); write(bytes); write('\nendstream'); } write('\nendobj\n'); };
    write('%PDF-1.4\n');
    object(1, '<< /Type /Catalog /Pages 2 0 R >>');
    object(2, '<< /Type /Pages /Count ' + pages.length + ' /Kids [' + pages.map((_, i) => (3 + i * 3) + ' 0 R').join(' ') + '] >>');
    pages.forEach((p, i) => {
      const id = 3 + i * 3, w = p.width * 72 / 150, h = p.height * 72 / 150;
      object(id, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Resources << /XObject << /Photo ${id + 1} 0 R >> >> /Contents ${id + 2} 0 R >>`);
      object(id + 1, `<< /Type /XObject /Subtype /Image /Width ${p.width} /Height ${p.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${p.bytes.length} >>`, p.bytes);
      const content = encoder.encode(`q ${w} 0 0 ${h} 0 0 cm /Photo Do Q`);
      object(id + 2, `<< /Length ${content.length} >>`, content);
    });
    const xref = length;
    write('xref\n0 ' + offsets.length + '\n0000000000 65535 f \n');
    offsets.slice(1).forEach(offset => write(String(offset).padStart(10, '0') + ' 00000 n \n'));
    write(`trailer\n<< /Size ${offsets.length} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
    return new Blob(chunks, {type: 'application/pdf'});
  }
  async function combine(files) {
    if (!files.length || !files.every(isPhoto)) throw new Error('한 문서로 묶을 때는 사진만 선택해 주세요.');
    const pages = [];
    for (const file of files) {
      let bitmap;
      try {
        bitmap = await root.createImageBitmap(file, {imageOrientation: 'from-image'});
        const edge = Math.min(8192, Math.max(2560, Math.max(bitmap.width, bitmap.height) * Math.min(1, 1600 / Math.min(bitmap.width, bitmap.height))));
        const scale = Math.min(1, edge / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale)); canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const ctx = canvas.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
        if (!blob) throw new Error();
        pages.push({width: canvas.width, height: canvas.height, bytes: new Uint8Array(await blob.arrayBuffer())});
        canvas.width = canvas.height = 1;
      } catch (_) { throw new Error(file.name + ': 사진을 처리하지 못했습니다. JPG 또는 PNG로 다시 선택해 주세요.'); }
      finally { if (bitmap) bitmap.close(); }
    }
    return new File([pdf(pages)], files[0].name.replace(/\.[^.]+$/, '') + '-pages.pdf', {type: 'application/pdf'});
  }
  root.DocumentPhotos = {isPhoto, combine, pdf};
})(window);
