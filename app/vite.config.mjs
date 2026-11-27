import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const __dirname = dirname(fileURLToPath(import.meta.url))

export const buildEntries = [
	{ name: 'learning', entry: 'src/main.js', fileName: 'learning.js', globalName: 'LearningApp' },
	{ name: 'learning-admin-settings', entry: 'src/admin-settings.js', fileName: 'learning-admin-settings.js', globalName: 'LearningAdminSettings' },
	{ name: 'learning-personal-settings', entry: 'src/personal-settings.js', fileName: 'learning-personal-settings.js', globalName: 'LearningPersonalSettings' },
	{ name: 'learning-privacy-page', entry: 'src/privacy-page.js', fileName: 'learning-privacy-page.js', globalName: 'LearningPrivacyPage' },
	{ name: 'learning-imprint-page', entry: 'src/impressum-page.js', fileName: 'learning-imprint-page.js', globalName: 'LearningImprintPage' },
]

export function createLearningViteConfig(entryName = 'learning', { emptyOutDir = true, watch = false } = {}) {
	const entryConfig = buildEntries.find((entry) => entry.name === entryName)
	if (!entryConfig) {
		throw new Error(`Unknown learning build entry: ${entryName}`)
	}

	return defineConfig({
		base: '/apps/learning/js/',
		define: {
			__VUE_OPTIONS_API__: true,
			__VUE_PROD_DEVTOOLS__: false,
			'process.env.NODE_ENV': JSON.stringify('production'),
			'process.env': JSON.stringify({}),
			appName: JSON.stringify('learning'),
			appVersion: JSON.stringify('0.1.0'),
		},
		// GitHub #65: drop template comments in the bundle. With a comment beside the root element
		// the production runtime renders a fragment, `$el` is no element and class/style/attributes
		// a parent passes are dropped without a warning (9 components, #75 TypeError).
		plugins: [vue({ template: { compilerOptions: { comments: false } } })],
		resolve: {
			alias: {
				'@': resolve(__dirname, 'src'),
			},
		},
		build: {
			outDir: 'js',
			copyPublicDir: false,
			cssCodeSplit: false,
			emptyOutDir,
			sourcemap: false,
			watch: watch ? {} : undefined,
			lib: {
				entry: resolve(__dirname, entryConfig.entry),
				name: entryConfig.globalName,
				fileName: () => entryConfig.fileName,
				cssFileName: entryConfig.fileName.replace(/\.js$/, ''),
				formats: ['iife'],
			},
			// Vite 8: Rolldown replaces Rollup. An iife library build never splits code, so the
			// former inlineDynamicImports is implied (Rolldown warned that it ignores it).
			rolldownOptions: {
				output: {
					assetFileNames: '[name][extname]',
				},
			},
		},
	})
}

const defaultEntry = process.env.LEARNING_ENTRY || 'learning'
const defaultEmptyOutDir = process.env.LEARNING_EMPTY_OUT_DIR !== '0'
const defaultWatch = process.env.LEARNING_WATCH === '1'

export default createLearningViteConfig(defaultEntry, {
	emptyOutDir: defaultEmptyOutDir,
	watch: defaultWatch,
})
