// ESLint flat config (ESLint 10, eslint-plugin-vue 10) — replaces .eslintrc.json (GitHub #71).
//
// Same rule set as the old eslintrc: eslint:recommended + vue essential (Vue 3) + the project
// rules below. Where ESLint 9/10 loosened eslint:recommended, the old setting is restored
// explicitly (marked "ESLint 8 parity"). What the new majors added (no-useless-assignment,
// no-unassigned-vars, preserve-caught-error, no-constant-binary-expression, ...) stays on.
// Which files `eslint src/` lints comes from the `files` patterns below, so no --ext is needed.
import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import tsParser from '@typescript-eslint/parser'
import tsPlugin from '@typescript-eslint/eslint-plugin'
import globals from 'globals'

export default [
	{
		// Built bundles and reports, never source.
		ignores: ['js/**', 'css/**', 'coverage/**', 'vendor/**', 'node_modules/**'],
	},
	js.configs.recommended,
	...pluginVue.configs['flat/essential'],
	{
		files: ['**/*.js', '**/*.mjs', '**/*.cjs', '**/*.vue', '**/*.ts'],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: {
				...globals.browser,
				...globals.node,
				...globals.es2021,
				t: 'readonly',
				n: 'readonly',
				OC: 'readonly',
				OCA: 'readonly',
				OCP: 'readonly',
			},
		},
		rules: {
			'vue/no-v-html': 'error',
			'vue/multi-word-component-names': 'off',
			'vue/no-mutating-props': 'warn',
			'no-console': 'off',
			'no-debugger': 'error',
			'no-eval': 'error',
			'no-implied-eval': 'error',
			// caughtErrors: ESLint 9 changed the default from "none" to "all". Kept at "none" so
			// the 207 `catch (e) {}` blocks do not turn into warnings that bury the real ones.
			'no-unused-vars': ['warn', { argsIgnorePattern: '^_', caughtErrors: 'none' }],
			// ESLint 8 parity: ESLint 9 changed the default to "allExceptWhileTrue".
			'no-constant-condition': ['error', { checkLoops: 'all' }],
			// ESLint 8 parity: dropped from eslint:recommended in ESLint 9, and its new default
			// (blockScopedFunctions "allow") would accept what ESLint 8 reported.
			'no-inner-declarations': ['error', 'functions', { blockScopedFunctions: 'disallow' }],
			// ESLint 8 parity: formatting rules dropped from eslint:recommended in ESLint 9.
			// Deprecated in core, available until ESLint 11 — move them to @stylistic/eslint-plugin then.
			'no-extra-semi': 'error',
			'no-mixed-spaces-and-tabs': 'error',
			'no-restricted-imports': ['error', {
				patterns: [{
					group: ['**/l10n/*.json'],
					message: 'Keine direkten l10n JSON-Imports - Webpack crasht an pluralForm. Nutze @nextcloud/l10n t() oder app/src/l10n/virtuprof-strings.js',
				}],
			}],
		},
	},
	{
		// CommonJS files (tests/print/*.cjs): require/module.exports, not ES modules.
		files: ['**/*.cjs'],
		languageOptions: {
			sourceType: 'commonjs',
		},
	},
	{
		files: ['**/*.ts'],
		languageOptions: {
			parser: tsParser,
		},
		plugins: {
			'@typescript-eslint': tsPlugin,
		},
		rules: {
			'no-unused-vars': 'off',
			'@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
		},
	},
]
