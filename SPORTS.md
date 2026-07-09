# Built-In Sports Research

This note documents the sport modules currently built into LeagueFlow and the rule-model assumptions used to keep one shared data model while allowing each sport to store different match details.

## Core approach

- Teams, players, and matches all live in the same WordPress post types.
- `lf_sport` links each record to a sport module.
- Sport modules define:
  - menu label
  - icon
  - standings column labels
  - scoring label
  - match structure
  - sport-specific optional match detail fields

## Built-in sports

| Sport | Model notes used in LeagueFlow | Example optional match fields | Source |
| --- | --- | --- | --- |
| Soccer | Two halves, low scoring, draws, cautions, dismissals | Scorers, yellow cards, red cards, notes | [IFAB Laws](https://www.theifab.com/laws) |
| Basketball | Four quarters, higher point totals, fouls, timeouts, overtime | Quarter scores, fouls summary, timeout notes | [NBA Rule No. 5](https://official.nba.com/rule-no-5-scoring-and-timing/) |
| American Football | Four quarters, possession-driven scoring, touchdowns, field goals, overtime | Quarter scores, scoring summary, turnover and penalty summary | [NFL Rulebook](https://operations.nfl.com/the-rules/nfl-rulebook/) |
| Baseball | Inning-based scoring with runs, hits, errors, pitching outcomes | Inning log, hits and errors, pitching summary | [MLB Official Information](https://www.mlb.com/official-information) |
| Hockey | Three periods, penalties, power plays, overtime and shootout context | Period scores, penalty summary, shots and power plays | [NHL Video Rulebook / Rulebook download](https://www.nhl.com/info/video-rulebook) |
| Volleyball | Best-of-five sets, rally scoring, libero and rotation context | Set scores, stats summary, rotation notes | [FIVB Basic Rules](https://www.fivb.com/volleyball/the-game/basic-rules/) |
| Cricket | Innings, overs, wickets, batting and bowling summaries | Innings summary, batting summary, bowling summary | [ICC Playing Conditions](https://www.icc-cricket.com/about/cricket/rules-and-regulations/playing-conditions) |
| Rugby | Two halves, try-based scoring plus conversions and penalties, discipline events | Scoring summary, card summary, substitution notes | [World Rugby Laws](https://passport.world.rugby/laws-of-the-game/) |

## Icon approach

- LeagueFlow uses bundled inline SVG icons for each built-in sport.
- Icons are intentionally simple and monochrome so they feel native in classic WordPress admin menus.
- No external icon framework is required.

## Why this structure works

- It avoids separate plugins or separate tables per sport.
- Shared admin screens and shared blocks keep the codebase maintainable.
- Sport modules can add new labels and match-detail fields without changing the core entities.
- Future sports can be added by extending the sports registry instead of redesigning the plugin.
