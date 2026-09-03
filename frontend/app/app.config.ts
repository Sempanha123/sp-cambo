// SP Cambo global UI theme.
// V3: compact buttons + semantic action colours while keeping the clean,
// rounded "Continue with Google" visual language.
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'blue',
      secondary: 'violet',
      success: 'emerald',
      info: 'cyan',
      warning: 'amber',
      error: 'rose',
      neutral: 'slate'
    },
    button: {
      slots: {
        base: [
          'sp-google-button',
          'inline-flex items-center justify-center font-semibold',
          'disabled:cursor-not-allowed aria-disabled:cursor-not-allowed',
          'disabled:opacity-60 aria-disabled:opacity-60',
          'transition-all duration-200 ease-out'
        ].join(' '),
        label: 'truncate',
        leadingIcon: 'shrink-0',
        trailingIcon: 'shrink-0'
      },
      variants: {
        color: {
          primary: 'sp-button-color-primary',
          secondary: 'sp-button-color-secondary',
          success: 'sp-button-color-success',
          info: 'sp-button-color-info',
          warning: 'sp-button-color-warning',
          error: 'sp-button-color-error',
          neutral: 'sp-button-color-neutral'
        },
        variant: {
          solid: 'sp-button-variant-solid',
          outline: 'sp-button-variant-outline',
          soft: 'sp-button-variant-soft',
          subtle: 'sp-button-variant-subtle',
          ghost: 'sp-button-variant-ghost',
          link: 'sp-button-variant-link'
        },
        size: {
          xs: {
            base: 'min-h-7 px-2.5 text-xs gap-1.5 rounded-lg',
            leadingIcon: 'size-3.5',
            trailingIcon: 'size-3.5'
          },
          sm: {
            base: 'min-h-8 px-3 text-xs gap-1.5 rounded-lg',
            leadingIcon: 'size-3.5',
            trailingIcon: 'size-3.5'
          },
          md: {
            base: 'min-h-9 px-3.5 text-sm gap-1.5 rounded-lg',
            leadingIcon: 'size-4',
            trailingIcon: 'size-4'
          },
          lg: {
            base: 'min-h-10 px-4 text-sm gap-2 rounded-xl',
            leadingIcon: 'size-4',
            trailingIcon: 'size-4'
          },
          xl: {
            base: 'min-h-11 px-5 text-sm gap-2 rounded-xl',
            leadingIcon: 'size-4.5',
            trailingIcon: 'size-4.5'
          }
        }
      },
      defaultVariants: {
        color: 'primary',
        variant: 'solid',
        size: 'md'
      }
    }
  }
})
