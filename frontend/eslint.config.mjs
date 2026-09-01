// @ts-check
import withNuxt from './.nuxt/eslint.config.mjs'

export default withNuxt({
  rules: {
    '@stylistic/arrow-parens': 'off',
    '@stylistic/indent': 'off',
    '@stylistic/max-statements-per-line': 'off',
    '@stylistic/member-delimiter-style': 'off',
    '@stylistic/no-multiple-empty-lines': 'off',
    '@stylistic/quote-props': 'off',
    '@stylistic/quotes': 'off',

    'vue/block-tag-newline': 'off',
    'vue/html-closing-bracket-newline': 'off',
    'vue/html-indent': 'off',
    'vue/html-self-closing': 'off',
    'vue/max-attributes-per-line': 'off',
    'vue/multiline-html-element-content-newline': 'off',
    'vue/singleline-html-element-content-newline': 'off'
  }
})
