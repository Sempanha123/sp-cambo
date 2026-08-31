SP Cambo Google first-login + referral OAuth fix

Replace these files in the project with the matching paths from this bundle:

frontend/app/stores/auth.ts
frontend/app/app.vue
frontend/app/composables/useReferralAttribution.ts
frontend/app/components/GoogleLoginButton.vue
backend/app/Http/Controllers/Api/V1/Auth/GoogleAuthController.php

Then run:

Backend:
  cd backend
  php -l app/Http/Controllers/Api/V1/Auth/GoogleAuthController.php
  php artisan test

Frontend:
  cd ../frontend
  npm run typecheck
  npm run build

What this fixes:
- Google success no longer gets cleared by an old "session expired" signal.
- Google should enter the dashboard on the first successful OAuth completion.
- Referral code is carried inside Laravel's encrypted OAuth state.
- Backend attaches referral before issuing the Google login bearer token.
- Inviter signup reward remains idempotent through existing ReferralService.
- Temporary session/network/rate-limit errors no longer erase referral attribution.
- Normal email registration also claims a captured referral after successful account creation.
- Google-created users explicitly persist email_verified_at even when that attribute is guarded.
