/**
 * Descarga de archivos que llegan como blob desde la API.
 *
 * El patrón "crear un <a>, hacerle click y olvidarlo" está repetido por varias
 * vistas; esas copias dejan el elemento colgando del DOM y nunca revocan el
 * object URL, así que el blob se queda en memoria hasta recargar la página. En
 * un CSV de miles de filas eso sí se nota. Aquí se limpia todo.
 */

/**
 * @param {BlobPart} data      Cuerpo de la respuesta (responseType: 'blob')
 * @param {string}   filename  Nombre con el que se guarda
 * @param {string}   type      MIME del archivo
 */
export function downloadBlob(data, filename, type = 'text/csv;charset=utf-8') {
    const url = URL.createObjectURL(new Blob([data], { type }))
    const link = document.createElement('a')

    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()

    // Sin esto el navegador retiene el blob completo hasta recargar.
    URL.revokeObjectURL(url)
}

/**
 * Nombre de archivo que propuso el servidor en `Content-Disposition`, con
 * respaldo por si el header no viaja (algún proxy puede recortarlo).
 */
export function filenameFromResponse(response, fallback) {
    const disposition = response?.headers?.['content-disposition'] || ''
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)

    return match ? decodeURIComponent(match[1]) : fallback
}
