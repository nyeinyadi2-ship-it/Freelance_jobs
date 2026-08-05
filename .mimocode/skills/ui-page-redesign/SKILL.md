---
name: ui-page-redesign
description: Redesign a PHP page's visual layer while preserving all backend logic. Use for company, freelancer, or admin page UI rewrites with Tailwind CSS, glassmorphism patterns, and consistent design tokens.
---

# UI Page Redesign Skill

Redesign a single PHP page's HTML/CSS while preserving all PHP logic, queries, and form handlers. This project uses procedural PHP 8.x, MySQL (mysqli), Tailwind CSS via CDN, and vanilla JS — no frameworks.

## Pre-flight checklist

1. **Read MEMORY files** for design tokens, CSS prefix conventions, and known gotchas:
   - `MEMORY.md` → ## Architecture decisions, ## Patterns, ## Gotchas
   - `MEMORY-architecture-ui-stable.md` → site-wide visual language, hero section rules
   - `MEMORY-company-redesign-gotchas.md` → company-specific redesign lessons
   - `MEMORY-layout-navigation-ui.md` → layout systems, navbar, CSS namespaces
   - `MEMORY-discovered-platform-historical.md` → platform facts, CSS patterns

2. **Read the target file** to understand:
   - Where `<!DOCTYPE html>` starts (or if it uses `header.php` / `freelancer_layout.php`)
   - All PHP logic blocks (queries, form handlers, redirects) — these are IMMUTABLE
   - Current CSS classes and inline styles
   - Any JavaScript (tabs, modals, validation, AJAX)

3. **Check CSS namespace** to avoid collisions:
   - `co-*` → company pages (`assets/css/company.css`)
   - `ff-*` → find freelancers page
   - `cnb-*` → navbar (`assets/css/navbar.css`)
   - `fl-*` → freelancer pages
   - `ph-*`, `gc-*`, `pa-*`, `sc-*`, `sb-*`, `pc-*`, `btn-*`, `tl-*`, `st-*`, `sst-*`, `rv-*`, `pm-*` → view_freelancer/view_portfolio glassmorphism pages
   - Page-specific: use a NEW prefix if none exists for this page

## Design tokens (canonical)

### Site-wide visual language
- **Body background:** `bg-gradient-to-br from-slate-50 via-white to-indigo-50/30`
- **Cards:** White bg, `border: 1px solid rgba(0,0,0,0.04)`, dual-layer box-shadow
- **Headings:** `text-gray-900 dark:text-white font-extrabold`
- **Section labels:** `.section-eyebrow` CSS class with `::before` pseudo-element gradient line
- **Sections:** `py-28` spacing
- **Primary button:** `.btn-gradient`
- **Secondary button:** White card with `border border-gray-200`
- **Transitions:** `0.4s cubic-bezier(0.4, 0, 0.2, 1)`

### Company pages (blue/white theme)
- CSS variables: `--co-blue: #2563eb`, `--co-blue-dark: #1d4ed8`, `--co-green: #059669`, `--co-amber: #d97706`, `--co-purple: #7c3aed`, `--co-red: #dc2626`
- Radius: `--co-radius: 0.875rem`
- Use `assets/css/company.css` — link after `header.php` include

### Glassmorphism pages (view_freelancer, view_portfolio)
- Indigo (#4f46e5) primary, purple (#7c3aed) accent
- Glass cards: `rgba(255,255,255,0.85)` + `backdrop-filter: blur(20px)` + translucent borders
- Hero: full-width blue→purple→indigo gradient with radial mesh overlays

## Procedure

### Step 1: Plan CSS prefix
Choose a 2-3 letter prefix for the page's custom CSS. Add it to the page's `<style>` block. Do NOT reuse another page's prefix unless the CSS is identical.

### Step 2: Preserve PHP logic
Identify all PHP blocks that contain:
- Database queries (`$conn->query`, `$conn->prepare`)
- Form handlers (`$_POST`, `$_GET`, `verify_csrf`)
- Session checks (`require_role`, `$_SESSION`)
- File uploads (`upload_image`, `upload_attachment`)
- Redirects (`redirect()`, `header('Location:')`)

These blocks are READ-ONLY. Do not modify, move, or delete them.

### Step 3: Rewrite HTML/CSS
Replace the visual layer between `<!DOCTYPE html>` (or after layout include) and `</html>`:
- Use Tailwind utility classes for layout, spacing, typography
- Use page-specific CSS classes for repeated components (cards, badges, buttons)
- Use inline `<style>` block for custom values (gradients, animations, glassmorphism)
- Keep all `<?= e(...) ?>` / `<?= $var ?>` data bindings intact
- Preserve form `action`, `method`, `enctype`, and hidden inputs (especially `csrf_token`)

### Step 4: Responsive design
- Mobile breakpoint: `768px` (`md:` in Tailwind)
- Stack columns on mobile, adjust font sizes, collapse sidebars
- Test: hero section, cards grid, tables, forms should all be usable on 375px width

### Step 5: Dark mode (if applicable)
- Use `dark:` Tailwind variants for background, text, borders
- Dark card bg: `rgba(30,41,59,0.5)` + `rgba(255,255,255,0.05)` border

### Step 6: Verify
- Run PHP lint: `php -l <file>`
- Check no PHP logic was accidentally removed (diff the PHP blocks)
- Check all form hidden inputs still present
- Check all `base_url()` links resolve

## Common pitfalls

- **Do NOT wrap `javascript:` URIs in `base_url()`** — produces broken URLs
- **`setcookie()` must be called before any HTML output** — check if the page calls `csrf_cookie()`
- **SELECT must include all keys used later** — if redesign touches queries
- **`redirect()` must call `session_write_close()` before `header()`** — already in config/auth.php
- **Navbar CSS class collisions** — `.nb` was used for both navbar and notification badges; badges now use `.badge`
- **`<?php if ($role !== 'company')` guards in navbar** — company users see different nav links
- **Full-bleed sections** need to escape `<main class="container">` — use negative margins or close main early
- **`onerror` on images** — job cards use company `logo_image` with gradient fallback via JS `onerror` handler
- **JS-polled badges must always be in DOM** — use `display:none|flex` toggle, not PHP conditional rendering

## Files commonly involved

- `includes/navbar.php` — shared navbar (do not modify unless specifically redesigning it)
- `includes/header.php` — shared HTML head + opening tags
- `includes/footer.php` — shared footer + closing tags
- `includes/freelancer_layout.php` — freelancer-specific layout wrapper
- `assets/css/company.css` — company design system (extend, don't replace)
- `assets/css/custom.css` — global custom styles
- `assets/css/navbar.css` — navbar styles
- `config/auth.php` — `e()`, `base_url()`, `require_role()`, `csrf_token()`, `verify_csrf()`
- `config/db.php` — database connection
