import js from '@eslint/js'
import globals from 'globals'
import pluginVue from 'eslint-plugin-vue'
import tsParser from '@typescript-eslint/parser'
import tsPlugin from '@typescript-eslint/eslint-plugin'

export default [
	{
		ignores: ['js/**', 'css/**', 'node_modules/**', 'vendor/**'],
	},
	js.configs.recommended,
	...pluginVue.configs['flat/essential'],
	{
		files: ['**/*.js', '**/*.vue', '**/*.ts'],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: {
				...globals.browser,
				...globals.es2021,
				...globals.node,
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
			'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
			'no-restricted-imports': ['error', {
				patterns: [{
					group: ['**/l10n/*.json'],
					message: 'Keine direkten l10n JSON-Imports - Webpack crasht an pluralForm. Nutze @nextcloud/l10n t() oder app/src/l10n/virtuprof-strings.js',
				}],
			}],
		},
	},
	{
		files: ['**/*.ts'],
		languageOptions: { parser: tsParser },
		plugins: { '@typescript-eslint': tsPlugin },
		rules: {
			'no-unused-vars': 'off',
			'@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
		},
	},
]
