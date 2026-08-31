# SP Cambo Dashboard Mobile + Fixed Profile R6

This is a full-file replacement package for the two problems shown in the screenshots.

## Fixed

### Phone `/dashboard/buy`
- stops the dashboard panel from becoming wider than the phone
- fixes cropped package/filter content on the right
- makes flex/grid children shrink with `min-width: 0`
- keeps package values inside cards
- allows descriptions to wrap on phones
- keeps the package list one column below the existing `sm` breakpoint
- preserves the R4/R5 visual classes and model artwork

### Dashboard profile/account
- dashboard group is exactly `100dvh`
- page content scrolls inside its panel
- sidebar stays viewport-height
- only sidebar navigation scrolls if needed
- account/profile dropdown stays visible at the bottom of the sidebar
- no need to scroll to the bottom of a long Buy/Models/Admin page to reach the profile button

## Full files

- `frontend/app/layouts/dashboard.vue`
- `frontend/app/components/SpDashboardPage.vue`

No backend changes and no migration.

## Apply

Extract over the project root and allow overwrite.

Then:

```powershell
cd frontend
npm run typecheck
npm run build
```

Test at approximately 390px, 430px and 500px widths:

```text
/dashboard/buy
/dashboard/models
/dashboard/playground
```

On desktop, open a very long Buy page and confirm the account/profile button is
still immediately visible at the bottom-left while only the main panel scrolls.
