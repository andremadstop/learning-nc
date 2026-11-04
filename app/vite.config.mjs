import { readFileSync, readdirSync } from 'fs'
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

// Rolldown (Vite 8) cannot fold some dynamic imports of @nextcloud/dialogs (FilePicker,
// PublicAuthPrompt) into the single IIFE bundle, even with codeSplitting: false, and aborts with
// "UMD and IIFE are not supported for code-splitting builds". The lazy imports are therefore
// replaced by a lookup in a registry that dist/index.mjs fills with static imports. The registry
// is filled AFTER chunks/index-*.mjs has run: those chunks call t() at module level, so importing
// them statically from inside chunks/index would hit a temporal dead zone.
const DIALOGS_REGISTRY = '__learningDialogsLazy'

function inlineDialogsDynamicImports() {
	const dialogsDist = /@nextcloud[\\/]dialogs[\\/]dist[\\/]/
	const dynamicImport = /import\("(\.\/[^"]+\.mjs)"\)/g
	return {
		name: 'learning:inline-dialogs-dynamic-imports',
		enforce: 'pre',
		transform(code, id) {
			const cleanId = id.split('?')[0]
			if (!dialogsDist.test(cleanId)) return null
			if (/[\\/]chunks[\\/]index-[^\\/]+\.mjs$/.test(cleanId)) {
				const out = code.replace(dynamicImport, (_m, file) => `Promise.resolve().then(() => globalThis.${DIALOGS_REGISTRY}[${JSON.stringify(file)}])`)
				return out === code ? null : { code: out, map: null }
			}
			if (/[\\/]dist[\\/]index\.mjs$/.test(cleanId)) {
				const chunksDir = resolve(dirname(cleanId), 'chunks')
				const files = readdirSync(chunksDir).filter((f) => /^index-.*\.mjs$/.test(f))
				const lazy = new Set()
				for (const f of files) {
					for (const m of readFileSync(resolve(chunksDir, f), 'utf8').matchAll(dynamicImport)) lazy.add(m[1])
				}
				if (!lazy.size) return null
				const lines = ['']
				;[...lazy].forEach((file, i) => {
					lines.push(`import * as __learningLazy${i} from ${JSON.stringify('./chunks/' + file.slice(2))};`)
				})
				lines.push(`globalThis.${DIALOGS_REGISTRY} = {`)
				;[...lazy].forEach((file, i) => lines.push(`\t${JSON.stringify(file)}: __learningLazy${i},`))
				lines.push('};')
				return { code: code + lines.join('\n') + '\n', map: null }
			}
			return null
		},
	}
}

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
		plugins: [inlineDialogsDynamicImports(), vue()],
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
			rolldownOptions: {
				output: {
					assetFileNames: '[name][extname]',
					codeSplitting: false,
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
