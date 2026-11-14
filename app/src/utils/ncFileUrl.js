import { generateUrl } from '@nextcloud/router'

/**
 * Nextcloud Files link for a file path such as '/Learning/Lernplan.md': opens its folder and
 * scrolls to the file.
 *
 * Audit #114: the sidebar bubble hard-coded '/apps/files/' (404 under a subpath such as
 * /nextcloud/ or without pretty URLs) and gave dir='' for a file in the root. Fullscreen did it
 * right; both now share this.
 *
 * @param {string} path file path in the user's Nextcloud
 * @return {string}
 */
export function ncFileUrl(path) {
	const normalizedPath = String(path || '')
	const lastSlashIndex = normalizedPath.lastIndexOf('/')
	const directory = lastSlashIndex > 0 ? normalizedPath.slice(0, lastSlashIndex) : '/'
	const fileName = lastSlashIndex >= 0 ? normalizedPath.slice(lastSlashIndex + 1) : normalizedPath
	return generateUrl('/apps/files/') + '?dir=' + encodeURIComponent(directory) + '&scrollto=' + encodeURIComponent(fileName)
}
