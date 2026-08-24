// Semantic colour roles for SP Cambo. The underlying scales are redefined in
// assets/css/main.css so every surface reuses the same tokens instead of
// per-page one-off colours.
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'indigo',
      secondary: 'cyan',
      success: 'emerald',
      info: 'sky',
      warning: 'amber',
      error: 'rose',
      neutral: 'slate'
    },
    button: {
      defaultVariants: {
        color: 'primary'
      }
    }
  }
})
