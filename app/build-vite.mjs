import { build } from 'vite'
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

if (watch && watchers.length) {
	const closeAll = async () => {
		await Promise.all(watchers.map((watcher) => watcher.close()))
		process.exit(0)
	}

	process.on('SIGINT', closeAll)
	process.on('SIGTERM', closeAll)
}
