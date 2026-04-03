import { build } from 'vite'
import { copyFileSync, mkdirSync, readdirSync } from 'fs'
import { join } from 'path'
import { buildEntries, createLearningViteConfig } from './vite.config.mjs'

const watch = process.argv.includes('--watch')
const watchers = []

for (const [index, entry] of buildEntries.entries()) {
	const config = createLearningViteConfig(entry.name, {
		emptyOutDir: index === 0,
		watch,
	})

	const result = await build({
		...config,
		configFile: false,
	})

	if (watch && result && typeof result.close === 'function') {
		watchers.push(result)
	}
}

// Copy CSS from js/ to css/ — NC's style() helper looks in css/, not js/
if (!watch) {
	mkdirSync('css', { recursive: true })
	for (const file of readdirSync('js')) {
		if (file.endsWith('.css')) {
			copyFileSync(join('js', file), join('css', file))
		}
	}
}

if (watch && watchers.length) {
	const closeAll = async () => {
		await Promise.all(watchers.map((watcher) => watcher.close()))
		process.exit(0)
	}

	process.on('SIGINT', closeAll)
	process.on('SIGTERM', closeAll)
}
