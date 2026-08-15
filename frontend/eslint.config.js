// Accessibility-focused ESLint config (flat). The frontend had jsx-a11y installed
// but no eslint/config, and the CI used `eslint --ext` (broken on ESLint 9) — so
// the admin UI's accessibility was never actually linted. Scoped to a11y.
import jsxA11y from 'eslint-plugin-jsx-a11y';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';

export default [
    { ignores: ['node_modules/**', 'dist/**', 'build/**'] },
    { linterOptions: { reportUnusedDisableDirectives: 'off' } },
    {
        files: ['src/**/*.{js,jsx,ts,tsx}'],
        languageOptions: {
            parser: tseslint.parser,
            parserOptions: { ecmaFeatures: { jsx: true }, sourceType: 'module' },
            globals: { ...globals.browser },
        },
        plugins: {
            'jsx-a11y': jsxA11y,
            '@typescript-eslint': tseslint.plugin,
            'react-hooks': reactHooks,
        },
        rules: {
            ...jsxA11y.flatConfigs.recommended.rules,
            // Our wrapping labels nest their text one level deeper than the
            // rule's default `depth: 2` (label > span > span > text). The
            // markup is correct — the control is wrapped and the text is
            // inside the label — so widen the search rather than restructure.
            'jsx-a11y/label-has-associated-control': ['error', { depth: 3 }],
        },
    },
];
