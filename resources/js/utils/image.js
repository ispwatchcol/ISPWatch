/**
 * Downscale + re-encode an image File in the browser before uploading it.
 *
 * Phone photos are routinely 4–12 MB each. Sending several of them in a single
 * multipart request blows past the gateway/nginx body-size limit; the request
 * then fails with a non-JSON 413/504 and the UI can only show a generic
 * "no se pudieron subir las fotos" (there is no JSON `message` to surface).
 * Shrinking each photo to `maxDim` px on its longest side and re-encoding as
 * JPEG at `quality` brings a whole batch down to a few hundred KB and keeps
 * every file comfortably under the API's 10 MB per-file validation rule.
 *
 * Non-image inputs, and any file that fails to decode or would grow, are
 * returned untouched so the caller always ends up uploading real bytes.
 *
 * @param {File} file
 * @param {{ maxDim?: number, quality?: number }} [opts]
 * @returns {Promise<File>}
 */
export async function compressImage(file, { maxDim = 1600, quality = 0.8 } = {}) {
  if (!(file instanceof File) || !/^image\//i.test(file.type)) return file

  let bitmap
  try {
    bitmap = await loadBitmap(file)
  } catch {
    return file // undecodable here — let the server deal with the original
  }

  const srcW = bitmap.width  || bitmap.naturalWidth
  const srcH = bitmap.height || bitmap.naturalHeight
  const { width, height } = fitWithin(srcW, srcH, maxDim)

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  if (!ctx) { bitmap.close?.(); return file }
  ctx.drawImage(bitmap, 0, 0, width, height)
  bitmap.close?.()

  const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality))
  // toBlob unsupported, or the re-encode ended up larger (already tiny/optimised):
  // keep whatever is smaller so we never make an upload worse.
  if (!blob || blob.size >= file.size) return file

  const name = file.name.replace(/\.[^.]+$/, '') + '.jpg'
  return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() })
}

/** Scale (w, h) down to fit inside a maxDim square, preserving aspect ratio. */
function fitWithin(w, h, maxDim) {
  const longest = Math.max(w, h)
  if (!longest || longest <= maxDim) return { width: w, height: h }
  const scale = maxDim / longest
  return { width: Math.round(w * scale), height: Math.round(h * scale) }
}

/**
 * Decode a File into something drawImage accepts. Prefers createImageBitmap
 * because it applies EXIF orientation (so portrait phone shots are not rotated)
 * and decodes off the main thread; falls back to an <img> element otherwise.
 */
async function loadBitmap(file) {
  if (typeof createImageBitmap === 'function') {
    try {
      return await createImageBitmap(file, { imageOrientation: 'from-image' })
    } catch {
      /* Older Safari ignores the options object — fall through to <img>. */
    }
  }

  const url = URL.createObjectURL(file)
  try {
    return await new Promise((resolve, reject) => {
      const img = new Image()
      img.onload = () => resolve(img)
      img.onerror = reject
      img.src = url
    })
  } finally {
    URL.revokeObjectURL(url)
  }
}
