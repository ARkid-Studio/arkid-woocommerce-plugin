// ESLint config — strict.
//
// Builds on @woocommerce/eslint-plugin/recommended (which already extends the
// @wordpress/eslint-plugin/recommended base) and tightens a handful of rules
// that catch real bugs.
module.exports = {
	extends: ['plugin:@woocommerce/eslint-plugin/recommended'],
	settings: {
		// The WooCommerce preset sets `import/resolver: 'typescript'`, but
		// eslint-import-resolver-typescript is only installed nested under
		// @wordpress/scripts, out of eslint-plugin-import's reach. It then falls
		// back to loading the `typescript` package itself as a resolver, which
		// fails, and every import/* rule silently stops resolving anything.
		// This is a plain-JS project, so the Node resolver is the right one.
		'import/resolver': {
			node: { extensions: ['.js', '.jsx', '.json'] },
		},
	},
	rules: {
		'react/react-in-jsx-scope': 'off',

		// `eslint-plugin-prettier` is incompatible with prettier 3.x's API
		// shape on newer Node releases; we run prettier separately via
		// `npm run format`.
		'prettier/prettier': 'off',

		// We are NOT WooCommerce — our text domain is 'arkid-catalogue-link'.
		'@wordpress/i18n-text-domain': [
			'error',
			{ allowedTextDomain: 'arkid-catalogue-link' },
		],

		// Bug-catching rules pushed to error level.
		eqeqeq: ['error', 'always'],
		'no-var': 'error',
		'prefer-const': ['error', { destructuring: 'all' }],
		'no-implicit-coercion': 'error',
		'no-implicit-globals': 'error',
		'no-throw-literal': 'error',
		'no-unused-expressions': [
			'error',
			{ allowShortCircuit: true, allowTernary: true },
		],
		'prefer-template': 'error',
		'no-param-reassign': ['error', { props: false }],
		'no-return-await': 'error',
		'no-unneeded-ternary': 'error',
		'no-shadow': 'error',
		curly: ['error', 'all'],
		'default-case-last': 'error',
		'no-else-return': ['error', { allowElseIf: false }],
	},
};
