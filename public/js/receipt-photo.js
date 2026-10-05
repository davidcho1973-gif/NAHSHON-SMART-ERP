(function (root) {
  'use strict';
  async function prepare(file) {
    if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type) || file.size <= 300 * 1024 || !root.createImageBitmap) return file;
    let bitmap;
    try {
      bitmap = await root.createImageBitmap(file, { imageOrientation: 'from-image' });
      const edge = Math.min(8192, Math.max(2560, Math.max(bitmap.width, bitmap.height) * Math.min(1, 1600 / Math.min(bitmap.width, bitmap.height))));
      const scale = Math.min(1, edge / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(bitmap.width * scale));
      canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.90));
      if (!blob || blob.size >= file.size) return file;
      return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', {type: 'image/jpeg', lastModified: file.lastModified});
    } catch (_) { return file; }
    finally { if (bitmap) bitmap.close(); }
  }
  root.ReceiptPhoto = { prepare };
})(window);
