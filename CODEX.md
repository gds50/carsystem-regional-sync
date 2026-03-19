# CODEX.md

You are working on a WordPress plugin project.

## Project goal
Build an MVP plugin for regional clone sites of `carsystem.su`.
The plugin is installed **only on the regional site** and synchronizes data from the main site over:

- WordPress REST API
- WooCommerce REST API
- HTTPS
- WordPress Application Passwords via Basic Auth

## Product boundaries
Do:
- settings page
- remote connection test
- dictionary-based SEO regionalization
- daily sync
- sync WooCommerce products
- sync WooCommerce categories
- sync WordPress pages
- maintain remote/local mapping
- logging
- caradmin-only access
- secure admin-only operations

Do not do in MVP:
- plugin on source site
- posts sync
- users sync
- comments sync
- orders sync
- stock sync
- 1C integration
- bidirectional sync
- smart NLP replacements

## Architecture rules
- PHP 8.1+
- Test on PHP 8.3
- Compatible with WordPress 6.9.x
- Compatible with WooCommerce 10.6.x
- No public unsafe endpoints
- All write actions require capability + username check + nonce
- Keep plugin structure simple and maintainable
- Prefer service classes over giant procedural files

## Parallel execution strategy
- Use parallel agents for independent documentation/test tasks
- Run linting and packaging in parallel when possible
- Split implementation by domain: admin, api, sync, persistence, tests

## Git discipline
- Commit after each logical change
- Use Conventional Commits in English
- Examples:
  - feat(admin): add settings registration
  - feat(sync): implement page upsert service
  - fix(api): handle wp error response
  - docs: update sync flow
  - test(sync): add dictionary parser cases

## Required reading order
1. `README.md`
2. `docs/01-prd-mvp.md`
3. `docs/02-architecture.md`
4. `docs/03-data-model.md`
5. `docs/04-api-contract.md`
6. `docs/06-implementation-plan.md`
7. `docs/07-test-plan.md`

## Development rules
- Keep business rules from docs authoritative
- Do not invent synchronization scope outside the PRD
- If uncertain, choose the simpler MVP-safe approach
- Keep sensitive data out of logs
- Always sanitize input and escape output
- Use WP APIs first, custom SQL only where justified
- Document every schema decision in `docs/03-data-model.md`

## Implementation priorities
1. plugin bootstrap + activation/deactivation
2. settings storage + admin page shell
3. remote API connection test
4. dictionary parser + regionalization service
5. sync mapping table
6. logging table
7. products sync
8. categories sync
9. pages sync
10. scheduled sync orchestration
11. polish and tests
