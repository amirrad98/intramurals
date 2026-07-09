# LeagueFlow

LeagueFlow is a classic WordPress plugin for running multi-sport leagues with teams, players, fixtures, derived standings, and knockout brackets. It uses WordPress-native admin screens, metaboxes, taxonomies, dynamic Gutenberg blocks, and shortcodes.

## Architecture

- `lf_team`, `lf_player`, and `lf_match` are shared custom post types used across all sports.
- `lf_sport` is the core taxonomy that assigns every team, player, and match to a sport module.
- `lf_league_level` assigns teams, players, matches, and calendar events to league levels such as Recreational and Competitive inside each sport.
- `lf_competition` and `lf_season` are taxonomies shared by teams and matches.
- `Sports_Manager` defines built-in sports, enables or disables sport modules, creates sport-specific admin menus, and exposes sport-specific match fields and icons.
- Standings are calculated from finished match scores and are never entered manually.
- Knockout brackets are built from match meta (`lf_is_knockout`, `lf_round_label`, `lf_round_order`, `lf_next_match_id`, `lf_next_match_slot`).
- Frontend blocks and shortcodes use the same server-side renderer for consistent output.
- LeagueFlow ships portable public templates for team pages, match pages, and match archives. Classic themes use the bundled PHP wrappers, and block themes receive plugin-registered block templates unless the active theme or Site Editor provides a more specific override.

## Folder Structure

```text
leagueflow/
├── assets/
│   ├── css/
│   └── js/
├── blocks/
│   ├── knockout-bracket/
│   ├── league-table/
│   ├── match-card/
│   ├── match-list/
│   ├── team-list/
│   └── team-roster/
├── includes/
│   ├── class-activator.php
│   ├── class-admin.php
│   ├── class-assets.php
│   ├── class-blocks.php
│   ├── class-knockout-service.php
│   ├── class-plugin.php
│   ├── class-post-types.php
│   ├── class-renderer.php
│   ├── class-rest-controller.php
│   ├── class-seeder.php
│   ├── class-settings.php
│   ├── class-shortcodes.php
│   ├── class-sports-manager.php
│   ├── class-standings-service.php
│   ├── class-taxonomies.php
│   ├── class-template-loader.php
│   └── helpers.php
├── templates/
├── SPORTS.md
├── leagueflow.php
├── README.md
└── uninstall.php
```

## Installation

1. Copy the `leagueflow` folder into `wp-content/plugins/`.
2. Activate **LeagueFlow** in WordPress admin.
3. Visit `LeagueFlow > Sports` and enable the sports you want to support.
4. Review `LeagueFlow > Settings` for points, display defaults, and slug settings.
5. Create competitions and seasons from the LeagueFlow admin menu.

## Data Model

- **Sport**: taxonomy term used to modularize one shared data model across soccer, basketball, football, baseball, hockey, volleyball, cricket, rugby, and future sport modules.
- **League Level**: taxonomy term used with Sport to split each sport into levels such as Recreational and Competitive.
- **Competition**: taxonomy term.
- **Season**: taxonomy term.
- **Team**: post with short name, city, coach, founded year, description, and featured image as logo.
- **Player**: post with team assignment, jersey number, position, age, nationality, captain flag, and featured image as photo.
- **Match**: post with fixture data, scores, status, sport-specific notes, and optional knockout settings.
- **Field Availability**: saved admin availability windows that describe when a field or venue can host matches. Matches can link back to the availability window used by the scheduling assistant.

## Admin Usage

### Sports Setup

- Start at `LeagueFlow > Sports`.
- Enable the sports you want active in the plugin.
- Each enabled sport gets its own left-hand WordPress admin menu with a sport-specific icon, overview page, and filtered team, player, match, standings, and bracket views.
- Disabled sports stay out of the menu so one install can be tailored to the sports you actually run.

### Teams

- Add teams from `LeagueFlow > Teams`.
- Sport-specific menus also provide sport-scoped team screens and `Add Team` links.
- Assign each team to a league level from the team details box.
- Use the main content editor for the team description.
- Use the featured image for the team logo.

### Players

- Add players from `LeagueFlow > Players`.
- Sport-specific menus also provide sport-scoped player screens and `Add Player` links.
- Assign each player to a team from the metabox.
- Mark captains with the captain checkbox.

### Matches

- Add fixtures from `LeagueFlow > Matches`.
- Sport-specific menus also provide sport-scoped match screens and `Add Match` links.
- Assign competition and season terms in the sidebar.
- Match sport is inherited from the selected teams so cross-sport fixtures are blocked.
- Match league level is inherited from the selected teams so recreational and competitive fixtures remain separate.
- Use the Scheduling Assistant in the match details box to auto-fill date/time and venue from field availability, or edit those fields manually to override the assistant.
- Mark a fixture as `finished` to have it counted in the league table.
- For knockout fixtures, enable `Knockout Match`, define the round label and order, and optionally choose the next match and slot.
- The match events box changes by sport. Soccer can store scorers and cards, basketball can store quarter scores and foul summaries, baseball can store inning lines, and so on.

### Field Availability

- Open `LeagueFlow > Field Availability`, or the sport-specific `Field Availability` page, to define recurring weekday or one-off date windows for each site field.
- Availability rules include field/venue name, sport scope, start and end times, match length, and optional buffer time.
- Use `Auto Schedule Matches` to fill missing match dates, venues, or both for a sport, competition, season, date range, or specific date.
- Existing matches and calendar events at the same venue are treated as conflicts, and teams are not placed into overlapping match slots.
- Manual match edits remain the override path unless the overwrite option is intentionally selected.

### League Levels

- Manage level terms from `LeagueFlow > League Levels`.
- Recreational and Competitive levels are created automatically.
- Use the level selector in standings, bracket, block, shortcode, and REST contexts to filter a sport into one level.

### Competitions and Seasons

- Manage competition terms from `LeagueFlow > Competitions`.
- Manage season terms from `LeagueFlow > Seasons`.
- Assign a sport to each competition and season so sport-specific pages, blocks, and filters stay consistent.
- Apply them to teams and matches so blocks, archives, standings, and brackets can filter correctly.

### Standings

- Visit `LeagueFlow > Standings` to preview calculated tables in admin.
- Points are controlled by plugin settings.

### Knockout Brackets

- Visit `LeagueFlow > Knockout Brackets` to preview the visual bracket.
- Winners can advance automatically into the next match when a knockout fixture is marked `finished`.
- If a match is decided by penalties or a ruling, use `Winner Override`.

## Gutenberg Blocks

- `League Table`
- `Sport Standings`
- `Team List`
- `Team Roster`
- `Match List`
- `Match Card`
- `Knockout Bracket`

All blocks are dynamic server-side blocks and inherit the classic frontend styling from the plugin. League table, team list, match list, calendar, and knockout bracket blocks support `sport` and `league_level` attributes so editors can target a specific sport module and level. Sport Standings displays enabled sports split by league level as expandable standings panels.

## Shortcodes

- `[league_table competition="spring-league" season="2026-spring" sport="soccer" league_level="recreational"]`
- `[sport_standings competition="spring-league" season="2026-spring"]`
- `[sport_standings sports="soccer,volleyball"]`
- `[team_roster team="123"]`
- `[match_list competition="spring-league" season="2026-spring" sport="soccer" league_level="competitive"]`
- `[knockout_bracket competition="2026-cup" season="2026-spring" sport="soccer" league_level="competitive"]`
- `[team_list competition="spring-league" season="2026-spring" sport="soccer" league_level="recreational"]`
- `[match_card match="456"]`

Shortcode attributes accept IDs or slugs where appropriate.

## REST API

Public read-only endpoints are available under:

- `/wp-json/leagueflow/v1/standings`
- `/wp-json/leagueflow/v1/matches`
- `/wp-json/leagueflow/v1/teams`
- `/wp-json/leagueflow/v1/bracket`
- `/wp-json/leagueflow/v1/league-levels`

Supported query parameters include `sport`, `league_level`, `competition`, `season`, `status`, `team`, `limit`, and `include_knockout`.

## Demo Data

Use `LeagueFlow > Settings > Generate Demo Data` to create:

- Sample competitions and a season
- Recreational and Competitive league levels
- Four teams
- Five players per team
- League fixtures
- Cup semifinals and a final with automatic winner advancement
- Example frontend pages using sport-aware shortcodes

## Local Testing Notes

1. Activate the plugin locally.
2. Generate demo data from settings.
3. Visit the generated pages:
   - `League Table`
   - `Fixtures & Results`
   - `Cup Bracket`
4. Edit a finished match score and confirm the standings update.
5. Edit a knockout match, mark it finished, and confirm the winner advances into the configured next match.
6. Insert LeagueFlow blocks in the block editor, select a sport, and compare frontend output.
7. Review [SPORTS.md](SPORTS.md) for the built-in sport model notes and research references that shaped the modular field design.

## Security and Standards

- Uses capability checks and nonces for admin actions.
- Sanitizes and escapes data in admin and frontend output.
- Translation-ready with the `leagueflow` text domain.
- Avoids external frameworks and uses WordPress-native APIs.
