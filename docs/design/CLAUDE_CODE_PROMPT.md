Paste this into Claude Code from the root of the Lamma Laravel project, after copying the `lamma-design-handoff/` folder into the project as `docs/design/`.

---

We have an approved UI design for Lamma (لمّة), our bilingual Arabic/English multiplayer quiz game. Everything is in `docs/design/`:

- `docs/design/HANDOFF.md`: the full UI spec (tokens, components, screens, states, RTL, motion, accessibility). Treat it as the source of truth.
- `docs/design/lamma-theme.css`: Tailwind v4 theme tokens and utilities.
- `docs/design/screens/*.html`: static reference screens. Open and read them for exact layout, spacing and copy, but **don't copy their inline styles**. Rebuild them with Tailwind classes and the tokens.
- The architecture, data model and events are in our Knowledge Base (already reflected in the routes and components named in HANDOFF.md).

Please do this in order, and stop after each step for me to review:

1. **Foundation.** Import `lamma-theme.css` into `resources/css/app.css`, add the Google Fonts link to the main layout, set `lang`/`dir` on `<html>` from the current locale, and add a language switcher. Put the brand logo SVGs in `public/images/brand/`. I'll supply the official files; use `docs/design/lamma-mark-rebuild.svg` until then.
2. **Blade components.** Build every component in HANDOFF.md §3 under `resources/views/components/lamma/`, with all variants and states. Add a hidden `/dev/components` route (local environment only) that renders each one in EN and AR so I can check them.
3. **Landing page** at `/`, matching `screens/landing-*.html`, responsive per §5, with all copy in `lang/en` and `lang/ar`.
4. **Auth:** restyle the Breeze/Fortify login and register views to match `screens/host-1-login.html`.
5. **Host screens:** `Host\CreateRoom`, `Host\HostLobby`, `Host\HostGame` (question + reveal states) and `Host\HostResults`. Use static placeholder data first, then wire them to Reverb events.
6. **Player screens:** `Player\JoinRoom`, `Player\PlayerLobby`, `Player\PlayerGame` (question, answered, correct, wrong and time's-up states) and `Player\PlayerResults`.

Rules:
- Use tokens only; no raw hex values in Blade.
- Use logical utilities (`ms-`/`me-`/`ps-`/`pe-`/`start`/`end`) so RTL mirrors automatically.
- Put every user-facing string in the lang files, for both locales.
- Every answer shows its shape as well as its colour.
- Respect `prefers-reduced-motion`.
- Keep the WCAG AA contrast rules in §1.
- If something isn't specified, check the reference screen first, then ask me rather than guess.
