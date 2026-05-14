# sat.trackr.live — Requirements Specification

**Version:** 1.0
**Domain:** sat.trackr.live
**Owner:** Robert (CyberSecDef)
**Stack:** PHP 8.2+ / sqlite / Cesium.js / satellite.js
**License:** TBD (recommend MIT or AGPL given the open-source data sources)

---

## 1. Vision & Differentiation

A public, read-only, mobile-friendly 3D web app that visualizes everything humans have put into Earth orbit — plus the events shaping that environment (launches, reentries, conjunctions, space weather) — in a single time-scrubbable globe.

**Competitive landscape:** Stuff in Space (beautiful but minimal data), Keep Track (data-rich but engineering-focused UI), satellitemap.space (Starlink-focused), Heavens-Above (text-heavy, dated UI). **Our wedge:** combine Stuff in Space's visual polish with Keep Track's data depth, plus surface launches/reentries/space-weather alongside the catalog so it's a *situational awareness* site, not just a tracker.

**Target audience:** space enthusiasts, amateur astronomers, journalists covering space, students, ham radio satellite operators, defense/cyber professionals interested in space domain awareness.

**Success criteria:**
- Globe loads to interactive state in <3s on desktop, <6s on mid-range mobile
- Renders 25,000+ objects at 30fps on desktop, 7,000 in mobile lite mode
- Data freshness: TLEs ≤6h old, launches ≤1h, space weather ≤15min
- Mobile-usable (not just mobile-tolerable)
- All data accessible via documented public JSON API

---

## 2. Technology Stack

### Backend
- **PHP 8.2+** (typed properties, readonly, enums, JIT)
- **Slim Framework 4** for routing (minimal, PSR-7 native) — or raw PHP with a tiny router if preferred
- **sqlite** for relational data (TLE history is the largest table — millions of rows)
- **APCu** or **Redis** for hot cache (current TLE lookups, top-N queries)
- **Composer** for dependencies
- Key libraries:
  - `guzzlehttp/guzzle` — HTTP client for ingestion
  - `monolog/monolog` — logging
  - `predis/predis` — Redis client if used
  - `phpunit/phpunit` — testing

### Frontend
- **Cesium.js** (latest, currently 1.121+) — 3D globe
- **satellite.js** — SGP4 propagation in browser
- **Vite** for asset bundling and dev server
- **TypeScript** strongly recommended (Cesium types are good)
- **Vanilla JS or Lit** for UI components — avoid React/Vue overhead since Cesium dominates the bundle anyway
- **Day.js** for time handling
- **Chart.js** or **uPlot** for statistics dashboards (uPlot if perf matters)
- **MapLibre GL** (optional) for 2D ground-track view

### Ingestion (cron-driven)
- PHP CLI scripts triggered via systemd timers or cron
- Each ingester is idempotent and resumable

### Hosting assumptions
- Single VPS or small cluster; CDN in front (Cloudflare recommended) for static assets and API caching
- HTTPS mandatory (Cesium requires it for some features)

---

## 3. System Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                    Browser (SPA)                              │
│  ┌──────────┐  ┌──────────────┐  ┌────────────────────────┐  │
│  │ Cesium   │  │ satellite.js │  │ UI panels / controls   │  │
│  │ viewer   │  │ propagator   │  │ (search, filters, etc) │  │
│  └────┬─────┘  └──────┬───────┘  └──────────┬─────────────┘  │
│       └────────fetch JSON──────────────────┘                  │
└─────────────────────────┬────────────────────────────────────┘
                          │ HTTPS / JSON
┌─────────────────────────▼────────────────────────────────────┐
│                  PHP API (Slim 4)                             │
│   /api/v1/satellites, /passes, /launches, /reentries, ...     │
│   Response cache (CDN edge + APCu/Redis hot layer)            │
└─────────────────────────┬────────────────────────────────────┘
                          │
            ┌─────────────┴───────────────┐
            │                             │
┌───────────▼──────────┐         ┌────────▼──────────────┐
│   sqlite            │         │  Redis / APCu cache   │
│   - satellites       │         │  - current TLEs       │
│   - tle_current      │         │  - top conjunctions   │
│   - tle_history      │         │  - space weather now  │
│   - launches         │         └───────────────────────┘
│   - reentries        │
│   - conjunctions     │
│   - space_weather    │
│   - ground_stations  │
│   - frequencies      │
│   - groups           │
│   - events           │
└───────────▲──────────┘
            │
┌───────────┴─────────────────────────────────────────────────┐
│              Ingesters (PHP CLI, cron-driven)                │
│  CelesTrak | Space-Track | N2YO | LL2 | SWPC | AMSAT | VIIRS │
└──────────────────────────────────────────────────────────────┘
```

---

## 4. Data Sources

### 4.1 CelesTrak (primary catalog) — no auth
- **Base:** `https://celestrak.org/NORAD/elements/gp.php`
- **Pull:** all standard groups (`active`, `stations`, `starlink`, `oneweb`, `gps-ops`, `glonass`, `galileo`, `beidou`, `weather`, `noaa`, `goes`, `science`, `geo`, `intelsat`, `iridium`, `cubesat`, `military`, `last-30-days`, `analyst`)
- **Format:** request `FORMAT=JSON` for forward compatibility (catalog hits 6-digit IDs in July 2026 and TLE format breaks)
- **Cadence:** every 6 hours
- **Companion:** SATCAT at `https://celestrak.org/satcat/records.php?GROUP=...&FORMAT=JSON` for metadata (country, launch date, RCS, status)
- **SOCRATES:** `https://celestrak.org/SOCRATES/` for conjunction predictions (parse the report)

### 4.2 Space-Track.org — free account required
- **Base:** `https://www.space-track.org/basicspacedata/query/...`
- **Auth:** username/password → cookie session (PHP cURL with CookieJar)
- **Pull:**
  - Decay predictions: `class/decay/...`
  - TIP messages (reentry impact predictions): `class/tip/...`
  - Historical TLE archive for any object on demand (rate-limited; lazy-fetch when user scrubs back in time)
- **Cadence:** every 12 hours for decay/TIP; on-demand for historical
- **Rate limits:** be respectful — they throttle aggressively. Cache aggressively, queue requests
- **Credentials:** store in `.env`, never commit

### 4.3 N2YO API — free API key
- **Base:** `https://api.n2yo.com/rest/v1/satellite/`
- **Auth:** API key in URL
- **Pull (on-demand, cached):**
  - `visualpasses/{id}/{lat}/{lon}/{alt}/{days}/{min_visibility}` — visual pass predictions
  - `radiopasses/{id}/{lat}/{lon}/{alt}/{days}/{min_elevation}` — radio passes
  - `positions/{id}/{lat}/{lon}/{alt}/{seconds}` — fallback if local SGP4 fails
- **Rate limits:** 1000 transactions/hour. Cache pass predictions per (sat, location, day) for 6h
- **Use:** complement to our own SGP4 — N2YO does magnitude estimation we'd otherwise need to compute

### 4.4 Launch Library 2 — no auth required
- **Base:** `https://ll.thespacedevs.com/2.2.0/`
- **Pull:**
  - `launch/upcoming/` — next 50 upcoming launches
  - `launch/previous/?limit=100&ordering=-net` — last 100 launches
  - `pad/` — launch sites (one-time + monthly refresh)
  - `agencies/` — operators (one-time + monthly refresh)
- **Cadence:** every 1h for upcoming; every 6h for previous
- **Free tier:** 15 requests/hour. Use the free tier carefully; consider $5/mo personal tier if traffic warrants

### 4.5 NOAA SWPC (Space Weather Prediction Center) — no auth
- **Base:** `https://services.swpc.noaa.gov/`
- **Pull:**
  - `products/noaa-planetary-k-index.json` — Kp index
  - `products/solar-wind/plasma-1-day.json` — solar wind
  - `json/goes/primary/xrays-1-day.json` — X-ray flux (solar flares)
  - `json/f107_cm_flux.json` — 10.7cm radio flux
  - `json/ovation_aurora_latest.json` — aurora oval (for globe overlay)
- **Cadence:** every 15 minutes
- **Use:** populate space weather widget + interpret effects on LEO drag/comms

### 4.6 AMSAT (amateur radio satellites) — public file
- **Source:** `https://www.amsat.org/tle/current/nasabare.txt` (TLE list) + frequency status page
- **Better source:** SatNOGS DB API at `https://db.satnogs.org/api/satellites/` and `/api/transmitters/` — fully structured JSON, no auth, comprehensive
- **Pull:** satellite metadata + downlink/uplink frequencies, modes, status (active/inactive)
- **Cadence:** weekly
- **Use:** populate "amateur radio info" section on object detail panels for hamsats

### 4.7 Implicit / static sources
- **VIIRS Black Marble** (NASA Earth Observatory) — light pollution overlay tile set, one-time download
- **Natural Earth** — country borders, populated places (for ground track context)
- **CSpOC announcements** — RSS at space-track for major events (decays, breakups)
- **McCants frequency lists** — fallback for amateur frequencies if SatNOGS gaps
- **Gunter's Space Page** — *manual* link-outs for satellite history (no API; use for outbound links only)

---

## 5. Database Schema

### `satellites`
| Column | Type | Notes |
|---|---|---|
| norad_id | INT PK | NORAD catalog number; will become BIGINT after 6-digit transition |
| intl_designator | VARCHAR(15) | YYYY-NNNAAA format |
| name | VARCHAR(100) | Current canonical name |
| alt_names | JSON | Array of historical names |
| object_type | ENUM | PAYLOAD, ROCKET_BODY, DEBRIS, TBA, UNKNOWN |
| status | ENUM | ACTIVE, INACTIVE, PARTIALLY_OPERATIONAL, DECAYED, UNKNOWN |
| operator | VARCHAR(100) | Indexed |
| country | VARCHAR(50) | ISO or SATCAT country code; indexed |
| launch_date | DATE | |
| launch_site_id | INT FK | |
| launch_vehicle | VARCHAR(100) | |
| mission | TEXT | |
| purpose | SET | comms,earth_obs,nav,science,military,human_sf,weather,station,tech_demo,unknown |
| orbit_class | ENUM | LEO, MEO, GEO, HEO, MOLNIYA, SSO, POLAR, GTO, UNKNOWN |
| rcs_meters | DECIMAL(6,2) | Radar cross-section in m² |
| size_class | ENUM | SMALL, MEDIUM, LARGE |
| mass_kg | INT | If known |
| dimensions | VARCHAR(100) | If known |
| has_3d_model | BOOLEAN | For marquee objects |
| image_url | VARCHAR(255) | Reference photo |
| wikipedia_slug | VARCHAR(100) | |
| decayed_at | DATETIME NULL | If decayed |
| created_at, updated_at | DATETIME | |

Indexes: `(country)`, `(operator)`, `(status, object_type)`, `(orbit_class)`, `(launch_date)`, `(name)` with fulltext.

### `tle_current`
Hot table — one row per active object, fast lookups.
| Column | Type |
|---|---|
| norad_id | INT PK FK |
| epoch | DATETIME(6) |
| line1 | CHAR(69) |
| line2 | CHAR(69) |
| mean_motion | DOUBLE |
| eccentricity | DOUBLE |
| inclination_deg | DOUBLE |
| raan_deg | DOUBLE |
| arg_perigee_deg | DOUBLE |
| mean_anomaly_deg | DOUBLE |
| bstar | DOUBLE |
| rev_number | INT |
| period_min | DOUBLE | computed |
| perigee_km | DOUBLE | computed |
| apogee_km | DOUBLE | computed |
| semimajor_km | DOUBLE | computed |
| source | ENUM | CELESTRAK, SPACE_TRACK |
| updated_at | DATETIME |

### `tle_history`
Append-only archive. Partition by epoch year for performance.
Same columns as above plus PK `(norad_id, epoch)`.

### `launches`
| Column | Type |
|---|---|
| id | VARCHAR(40) PK | LL2 UUID |
| name | VARCHAR(200) |
| net | DATETIME | No earlier than |
| status | ENUM | GO, TBD, HOLD, SUCCESS, FAILURE, PARTIAL_FAILURE |
| provider | VARCHAR(100) |
| vehicle | VARCHAR(100) |
| pad_id | INT FK |
| mission_name | VARCHAR(200) |
| mission_type | VARCHAR(50) |
| orbit_target | VARCHAR(50) |
| customer | VARCHAR(200) |
| webcast_url | VARCHAR(500) |
| image_url | VARCHAR(500) |
| description | TEXT |
| associated_norad_ids | JSON | Filled in after launch when TLEs appear |
| updated_at | DATETIME |

### `launch_sites`
| id | name | lat | lon | country | operator | description |

### `reentries`
| Column | Type |
|---|---|
| id | BIGINT PK |
| norad_id | INT FK |
| predicted_decay | DATETIME |
| confidence_window_hours | DECIMAL(5,2) |
| source | ENUM | SPACE_TRACK_TIP, CELESTRAK_SATCAT, COMPUTED |
| risk_score | DECIMAL(3,2) NULL | Higher for larger / less controlled |
| created_at | DATETIME |

### `conjunctions`
SOCRATES top-N close approaches.
| id | sat1_norad | sat2_norad | tca (time of closest approach) | min_range_km | rel_velocity_km_s | source | created_at |

### `space_weather`
| timestamp | kp | ap | f107 | xray_flux_class | solar_wind_speed | bz | aurora_power_gw |
Indexed by timestamp; retain 90 days.

### `frequencies`
| norad_id | direction (UP/DOWN) | freq_mhz | mode | service (amateur/commercial) | status | description |

### `ground_stations`
| id | name | network (DSN/ESTRACK/KSAT/SATNOGS/AMATEUR) | lat | lon | alt_m | bands | url |

### `groups`
Predefined groupings users can toggle. Mirror CelesTrak groups + custom ones (e.g. "Cubesats under 1U", "Operational GPS only").
| id | slug | name | description | dynamic_query JSON NULL |

### `group_members`
| group_id | norad_id |

### `events`
Generic event log for the "what's happening" feed.
| id | event_type ENUM(LAUNCH, DECAY, DEPLOYMENT, MANEUVER, BREAKUP, CONJUNCTION_WARNING, NAMING_CHANGE) | norad_id NULL | launch_id NULL | occurred_at | details JSON | source | created_at |

---

## 6. Backend API (REST, JSON)

All endpoints return JSON. Versioned at `/api/v1/`. CORS open (`*`). ETags on every response. Documented at `/api/docs` (OpenAPI 3 spec).

### Catalog
- `GET /api/v1/satellites` — paginated list. Filters: `?country=`, `?operator=`, `?type=`, `?status=`, `?orbit_class=`, `?purpose=`, `?launched_after=`, `?launched_before=`, `?q=` (name search), `?page=`, `?limit=` (max 500)
- `GET /api/v1/satellites/{norad}` — full detail
- `GET /api/v1/satellites/{norad}/tle` — current TLE (JSON OMM + line1/line2)
- `GET /api/v1/satellites/{norad}/tle/history?from=&to=&limit=` — historical TLEs
- `GET /api/v1/satellites/{norad}/state?at=` — propagated state vector at given time (lat/lon/alt, velocity, ECI)
- `GET /api/v1/satellites/{norad}/passes?lat=&lon=&alt=&days=&visible_only=` — pass predictions
- `GET /api/v1/satellites/{norad}/footprint?at=` — circle of visibility on ground
- `GET /api/v1/satellites/{norad}/orbit?from=&to=&step=` — array of positions for orbit ribbon rendering
- `GET /api/v1/satellites/{norad}/conjunctions` — upcoming close approaches
- `GET /api/v1/satellites/{norad}/frequencies` — radio frequencies if any
- `GET /api/v1/satellites/{norad}/events` — event history

### Groups
- `GET /api/v1/groups` — all groups
- `GET /api/v1/groups/{slug}` — group metadata + member NORAD IDs
- `GET /api/v1/groups/{slug}/tles` — bulk TLE dump for a group (efficient for client-side propagation of large sets)

### Launches
- `GET /api/v1/launches/upcoming?limit=`
- `GET /api/v1/launches/recent?limit=&days=`
- `GET /api/v1/launches/{id}`
- `GET /api/v1/launch-sites`

### Reentries
- `GET /api/v1/reentries/upcoming?within_hours=`
- `GET /api/v1/reentries/{norad}` — details for a specific decay

### Conjunctions
- `GET /api/v1/conjunctions/critical?limit=&min_probability=` — top risk events
- `GET /api/v1/conjunctions/upcoming?within_hours=`

### Space Weather
- `GET /api/v1/space-weather/current`
- `GET /api/v1/space-weather/history?hours=`
- `GET /api/v1/space-weather/aurora` — current ovation data (lat/lon power grid)

### Ground Stations
- `GET /api/v1/ground-stations?network=`

### Search
- `GET /api/v1/search?q=` — universal: matches NORAD ID, intl designator, name (fuzzy), operator
- `GET /api/v1/autocomplete?q=` — typeahead

### Stats
- `GET /api/v1/stats/catalog` — totals by type/status/country
- `GET /api/v1/stats/constellations` — Starlink/OneWeb/Kuiper/Iridium counts over time
- `GET /api/v1/stats/launches?year=` — launch counts and success rates
- `GET /api/v1/stats/decays?days=` — recent decay rate

### Feeds (for syndication / future trackr.live cross-linking)
- `GET /api/v1/feed/events.rss` — RSS of major events
- `GET /api/v1/feed/launches.json` — JSON Feed
- `GET /api/v1/feed/decays.json`

---

## 7. Frontend SPA Layout

### Default view (desktop)
```
┌──────────────────────────────────────────────────────────────────────┐
│ [logo] sat.trackr.live    [search bar]              [Now] [⚙][?][🌐] │  ← top bar
├──────────┬──────────────────────────────────────────────┬────────────┤
│          │                                              │            │
│ FILTERS  │                                              │  DETAIL    │
│ & LAYERS │              CESIUM GLOBE                    │  PANEL     │
│          │                                              │  (object   │
│ [groups] │              25,000 dots                     │   info or  │
│ [types]  │              orbits, ground stations         │   stats    │
│ [orbits] │              terminator, aurora              │   if none  │
│ [country]│                                              │   selected)│
│ [layers] │                                              │            │
│          │                                              │            │
├──────────┴──────────────────────────────────────────────┴────────────┤
│ [▶] ──●────────────── timeline ────────────────── [1x] [📅] [📍ME]   │  ← bottom bar
│      now-7d         now                  now+7d                       │
└───────────────────────────────────────────────────────────────────────┘
```

### Mobile view
- Top bar collapses to logo + hamburger + search icon
- Side panels become bottom drawers (swipe up to reveal)
- Timeline collapses to play/pause + "Now" pill; tap to expand
- Detail panel becomes a sheet that slides up from bottom

### Routing (URL-driven SPA)
- `/` — globe, default view
- `/satellite/{norad}` — globe with object selected and camera focused
- `/launch/{id}` — launch detail overlay
- `/event/{id}` — event detail
- `/conjunction/{id}` — close-approach replay scene
- `/decays` — upcoming reentries view
- `/launches` — launch tracker view
- `/stats` — stats dashboard
- `/about`, `/api`, `/data-sources`
- Hash params for state: `?at=ISO&observer=lat,lon&layers=lp,gs,ao&group=starlink`

---

## 8. 3D Scene Features (Showcase tier)

### Earth & environment
- High-res Bing imagery or NASA Blue Marble base layer (toggleable)
- Custom atmosphere shader (Cesium has one built-in, tweak parameters)
- Smooth day/night terminator with civil/nautical/astronomical twilight bands
- Real-time sun position with directional light + lens flare
- Real-time moon with correct phase, lit by sun
- Star field background (BSC5 or HYG database, ~9000 stars, magnitude-graded)
- Optional Milky Way panorama background

### Overlays (toggleable)
1. **Light pollution** — VIIRS Black Marble tinted overlay, opacity slider
2. **Aurora oval** — NOAA SWPC ovation grid, color-graded by power
3. **Country borders** — Natural Earth, thin lines, low opacity
4. **Magnetic field lines** — IGRF dipole approximation
5. **Geostationary belt** — thin ring at GEO altitude
6. **Van Allen belts** — translucent toroids (educational toggle)
7. **Ground station coverage cones** — for selected networks (DSN, ESTRACK)
8. **Sensor network ranges** — Space Surveillance Network FoV (educational/approximate)

### Objects
- **Point primitives** for the bulk catalog (Cesium Primitive API, not Entity — critical for performance)
- Color coding by type: payloads (cyan), rocket bodies (orange), debris (red), TBA (gray)
- Brightness scaled by size (RCS)
- **3D models** for marquee objects (load on zoom-in):
  - ISS (most detailed model, since it's the showpiece)
  - Tiangong
  - Hubble
  - JWST (note: not Earth-orbit but render at L2 if camera permits)
  - Starship (when in orbit)
  - Dragon, Cygnus, Progress, Soyuz
  - Notable historical: Mir wreckage path, Skylab
- **Orbit ribbons** for selected object: forward/back N orbits, color by altitude
- **Ground track** projected to surface
- **Sub-satellite footprint circle** showing visibility region

### Ground stations
- Markers for DSN (Goldstone, Madrid, Canberra), ESTRACK, KSAT, NEN, SatNOGS amateur network
- Click for station info + which satellites currently above horizon

### Launch sites
- Markers at all active pads
- Pulse animation when launch imminent (<24h)
- Trajectory arc for in-flight launches

### Reentry corridors
- For predicted decays in next 24h, render the ground track arc with confidence band

### Conjunction visualization
- For selected upcoming conjunctions, render both objects' trajectories converging with a "miss distance" indicator

---

## 9. Filtering & Layer System

Left panel, accordion sections:

**Object filters (multi-select):**
- Type: Payloads / Rocket Bodies / Debris / TBA
- Status: Active / Inactive / Decayed / Unknown
- Orbit: LEO / MEO / GEO / HEO / Molniya / SSO / Polar / GTO
- Purpose: Communications / Earth Obs / Navigation / Science / Military / Human SF / Weather / Stations / Tech Demo
- Country: dropdown with multi-select, "Top 10" shortcut
- Operator: searchable dropdown
- Size: Small / Medium / Large
- Age: < 30d / < 1y / < 5y / All
- Constellation membership: Starlink / OneWeb / Kuiper / GPS / GLONASS / Galileo / BeiDou / Iridium / etc.

**Predefined groups (single-click toggles):**
Mirror CelesTrak groups plus curated extras: "Recent launches (30d)", "Currently visible from your location", "Decaying in next 7 days", "Crewed vehicles", "Hubble + JWST + Webb-class", "Spy sats (KH-11 etc.)", "ISS visitors right now"

**Layers (overlays):**
- Light pollution / Aurora oval / Geostationary ring / Country borders / Magnetic field / Ground stations / Sensor cones / Van Allen belts / Star field / Milky Way / Launch sites

**View modes:**
- 3D globe (default)
- 2D ground track (Mercator with all selected sats' tracks)
- Polar view (top-down on N or S pole — great for SSO)
- Heliocentric (for showing orbits in space frame, not Earth-fixed)

---

## 10. Object Detail Panel (the data-dense centerpiece)

When user clicks an object, right panel populates with:

**Header**
- Name, NORAD ID, intl designator, status badge, type badge
- 3D model thumbnail / reference photo
- Quick actions: Track (camera follow), Show orbit, Show ground track, Show footprint, Share link, Copy TLE, View raw API

**Identity**
- Operator, country (flag), purpose tags, mission summary
- Launch date, launch site (linked), launch vehicle, launch mission name
- Age (years/months in orbit)
- Mass (kg), dimensions, RCS (m²), size class
- Wikipedia link, Gunter's Space Page link, N2YO link, Heavens-Above link

**Current state** (live-updating)
- Latitude / longitude / altitude (km)
- Velocity (km/s, both inertial and ground-relative)
- Footprint radius (km)
- Sub-satellite point (clickable to fly camera)
- Sunlit / in Earth shadow

**Orbital elements**
- Epoch (with age warning if >7 days old)
- Period (min), inclination (°), eccentricity
- Perigee / apogee altitude (km)
- Semi-major axis (km)
- RAAN (°), argument of perigee (°), mean anomaly (°)
- Mean motion (rev/day), revolution number
- B* drag term
- Computed: orbital energy, specific angular momentum, decay rate estimate

**Visibility from observer** (when location set)
- Currently above horizon? Y/N with elevation/azimuth
- Range (km)
- Estimated magnitude (visual)
- Visible to naked eye right now? Y/N
- Next 5 passes table: rise / peak / set / max elevation / max magnitude / direction
- Sky chart visualization of next pass (mini polar plot)

**Radio info** (if applicable)
- Downlink/uplink frequencies
- Modes (FM, SSB, CW, digital)
- Active transmitters (from SatNOGS)
- Doppler at current position

**Events & history**
- Launch event
- Major maneuvers (if logged)
- Anomalies, breakups
- Predicted decay (if applicable, with countdown)

**Conjunctions**
- Upcoming close approaches in next 7 days, sorted by miss distance
- Click to fly camera to the encounter

**Raw data**
- Current TLE (Line 1 & 2, copy button)
- OMM JSON expandable
- Direct API URL for this object

---

## 11. Time Controls

**Bottom timeline scrubber:**
- Default range: now-7d to now+7d
- Draggable handle; click anywhere on track to jump
- Time labels at 1h / 6h / 1d / 1w / 1mo zoom levels
- Mouse-wheel over scrubber zooms range

**Playback controls:**
- Play / Pause / Reset to now
- Speed buttons: 0.5x, 1x, 10x, 60x, 600x, 3600x (per real second of playback)
- "Smart speed" — auto-adjusts so visible orbits complete in ~5s

**Quick-jump menu:**
- Now
- Next sunset / sunrise (at observer location)
- Next ISS pass (at observer location)
- Tomorrow noon
- Specific date/time picker

**Accuracy warnings:**
- Yellow banner when scrubbed >48h from current TLE: "SGP4 accuracy degrades beyond ±2 days of epoch"
- Red banner when >7 days: "Significant position error expected"
- For ranges >14 days, auto-fetch historical TLE closest to selected time (if available) and re-propagate

**Time domain:**
- Range: 1957-10-04 (Sputnik 1 launch) to now+30 days
- Pre-now uses tle_history; post-now uses tle_current propagation

---

## 12. Search

- Header search bar with autocomplete dropdown
- Indexed: NORAD ID (exact), intl designator (exact), name (fulltext + fuzzy), operator (fulltext), launch mission name
- Autocomplete shows: thumbnail (if marquee), name, NORAD ID, country flag, status badge
- Empty-state suggestions: "ISS", "Hubble", "Starlink-1234", "GPS BIIF-12", "China space station"
- Search results page for ambiguous queries

**Smart query parsing:**
- `25544` → direct to ISS
- `starlink 30000` → Starlink constellation member
- `country:china type:payload status:active` → filter expression
- `near:51.5N,0E` → currently above given location

---

## 13. Pass Predictions

**Observer location:**
- Browser Geolocation API (with permission prompt)
- Manual entry: city search (Nominatim) or lat/lon/elevation
- Persisted in localStorage
- Indicator pill in top bar showing current observer

**Pass list view:**
- For a selected satellite: next 10 passes
- For "passes today": all visible passes for chosen group
- Each row: rise time, duration, max elevation, max magnitude, start/end direction
- "Visible only" filter (sat illuminated + observer in darkness)
- Magnitude threshold slider

**Sky chart for individual pass:**
- Polar plot, N up, looking up
- Track of satellite arc across sky
- Star background at pass time
- Other simultaneously visible sats overlaid

**Calendar export:**
- ICS download for selected passes
- "Add all ISS passes this week" one-click

**Radio passes (for hamsats):**
- Filter to satellites with active transmitters
- Show AOS/LOS, max elevation, footprint times
- Doppler shift estimate

---

## 14. Launches View

`/launches` route:

**Upcoming list:**
- Sortable by NET, provider, vehicle, destination
- Countdown timer per launch (DDD:HH:MM:SS)
- Status badge (GO / TBD / HOLD)
- Webcast button when available
- Filter by provider, vehicle, orbit destination
- Map view showing pads with upcoming activity

**Launch detail:**
- Hero image
- Countdown
- Mission summary
- Payload list (with links to NORAD entries once cataloged)
- Provider/vehicle info
- Pad info with map preview
- Webcast embed
- Description
- Trajectory arc on globe (if data available)

**Recent launches:**
- Last 90 days
- Cross-linked to TLE entries that appeared post-launch
- Success/failure indicator

**Stats:**
- Launches per year (1957–present chart)
- Top providers this year
- Success rate by vehicle

---

## 15. Reentries / Decays View

`/decays` route:

- Upcoming predicted decays sorted by predicted time
- Each entry: object name, mass estimate, predicted window (with confidence band), risk score
- Reentry corridor visualization on mini-globe
- "Notable" reentries flagged (large mass, uncontrolled, recent significance)
- Today's decays list
- Historical archive: notable past reentries with details

**Risk scoring:**
- Higher = bigger, less controlled, more populated ground track
- Display as 1-5 scale with explanation tooltip

---

## 16. Conjunctions View

`/conjunctions` route:

- SOCRATES top-N close approaches in next 7 days
- Filter by miss distance threshold
- Each row: both objects, TCA, miss distance (km), relative velocity (km/s)
- Click to launch "conjunction replay scene": camera flies to TCA, both orbits drawn, miss-distance indicator
- Cross-link to both objects' detail panels

---

## 17. Space Weather Widget

Top-right corner, expandable:

**Compact:**
- Kp number with color (green/yellow/red)
- Solar flux (sfu)
- "Quiet" / "Active" / "Storm" label

**Expanded:**
- Kp history graph (last 7 days)
- 27-day Kp forecast
- Current X-ray flux with flare class (A/B/C/M/X)
- Solar wind speed
- Bz component
- Aurora forecast: visible from latitude ___
- "Effects on satellites" interpretation:
  - LEO drag elevated (>K6): note increased decay rates
  - Polar comms blackout (X-class flare): note HF disruption
  - Radiation belt enhancement: note SEU risk for electronics

**Aurora oval overlay** — toggleable layer on globe, refreshes every 15 min

---

## 18. Statistics Dashboard

`/stats` route:

- **Catalog overview:** total objects, active payloads, debris count, by type/country pie charts
- **Constellation growth:** stacked area chart of Starlink/OneWeb/Kuiper/Iridium over time
- **Launch trends:** launches per year (1957–now), by provider, by orbit destination
- **Decay rate:** monthly decays, last 5 years
- **Country breakdown:** treemap of payloads by owner
- **Orbital regime population:** LEO/MEO/GEO/HEO distribution
- **Daily new objects:** rolling 30-day chart
- **Most-tracked satellites:** based on detail-page hits (anonymous analytics)

All charts exportable as PNG and the underlying data as CSV/JSON.

---

## 19. Mobile Considerations

**Performance budget (mobile):**
- Initial bundle ≤500KB compressed (excluding Cesium)
- Cesium loaded async, with progress bar
- Lite mode default: only "active" group (~7K objects), no aurora/light-pollution/Milky Way overlays, point primitives only (no 3D models)
- Frame rate target: 30fps; auto-reduce object count if dropping below 20fps

**Touch interactions:**
- One-finger drag: rotate globe
- Two-finger pinch: zoom
- Two-finger drag: pan camera (Cesium default)
- Tap object: select
- Long-press: context menu (set as observer location, fly to)

**Layout:**
- Panels as bottom sheets (swipe to reveal)
- Search expands to full-screen overlay
- Timeline collapses to a single play/pause button + "Now" pill
- Detail panel slides up from bottom, dismissible

**PWA:**
- Manifest with icons (192/512)
- Service worker caching shell + Cesium assets
- "Add to home screen" prompt
- Works offline with last-cached TLEs (read-only, banner indicating staleness)

**Battery:**
- Battery Status API check; if discharging and <20%, prompt for "low power mode"
- Low power mode: reduce frame rate to 15fps, disable orbit ribbons, dim atmosphere

**Mobile network:**
- Detect 2G/slow-3G; default to bulk TLE endpoint vs many individual requests
- Image quality scaled (Bing imagery low-res variant)

---

## 20. Performance & Caching Strategy

**Ingestion cadence (cron):**
- Every 15 min: NOAA SWPC, SOCRATES check
- Every 1 h: Launch Library 2 upcoming
- Every 6 h: CelesTrak full catalog refresh
- Every 12 h: Space-Track decay/TIP, LL2 recent
- Daily: SATCAT metadata, stats aggregation, tle_history cleanup (keep 365 days hot, archive older)
- Weekly: SatNOGS frequencies, ground station list, AMSAT TLE backup

**API response caching:**
- CDN edge: 60s for live data, 1h for static metadata, 24h for historical
- ETag on every response
- `Cache-Control: public, s-maxage=N, stale-while-revalidate=N*2`
- APCu hot cache for current TLE lookups (in-process, fastest)
- Redis for cross-process: top conjunctions, current space weather

**Database tuning:**
- Indexes per table noted above
- `tle_history` partitioned by year
- Read replicas if traffic warrants
- Use prepared statements everywhere

**Frontend:**
- Bulk TLE endpoint per group (e.g. all 7000 Starlink TLEs as one ~1.4MB JSON download, gzip → ~300KB)
- Web Worker for SGP4 propagation (don't block main thread)
- Cesium primitive batching
- Frustum culling free with Cesium

---

## 21. Accessibility

- WCAG 2.1 AA target
- All controls keyboard-navigable (Tab order well-defined)
- ARIA labels on Cesium custom controls
- Screen reader-friendly object detail panel (semantic HTML, not just visual)
- Reduced motion preference: disables orbit animation, atmospheric shimmer, smooth camera flights
- High-contrast theme toggle
- Color-blind safe palettes (Viridis or Cubehelix for data viz; default object colors checked against deuteranopia/protanopia)
- Text scaling: respects browser zoom up to 200%
- Focus indicators visible on all interactive elements

---

## 22. SEO & Sharing

- Pretty URLs: `/satellite/25544`, `/launch/spacex-starlink-9-3`
- Per-object server-rendered HTML with full metadata for crawlers (progressive enhancement: SPA hydrates after load)
- OG image generation: per-object PNG with globe snapshot + key facts (use headless Chrome via PHP shell exec, cached)
- Twitter card metadata
- JSON-LD structured data (`Thing` or custom schema)
- Sitemap.xml auto-generated, includes top 10,000 most-relevant objects + all launches + main routes
- robots.txt allows indexing, disallows /api/v1/*/history endpoints (too heavy)
- Share button: copies deep link with current camera position, time, and selected object encoded in URL fragment

---

## 23. Cron Jobs Manifest

| Job | Schedule | Script | Notes |
|---|---|---|---|
| ingest_celestrak_groups | 0 */6 * * * | `bin/ingest celestrak` | All standard groups |
| ingest_celestrak_satcat | 0 4 * * * | `bin/ingest satcat` | Daily metadata |
| ingest_socrates | */15 * * * * | `bin/ingest socrates` | Conjunctions |
| ingest_space_track_decay | 0 */12 * * * | `bin/ingest spacetrack decay` | TIP messages |
| ingest_space_track_historical | on-demand | `bin/ingest spacetrack tle {norad} {date}` | Queued |
| ingest_launch_library | 0 * * * * | `bin/ingest ll2 upcoming` | Hourly |
| ingest_launch_library_past | 0 */6 * * * | `bin/ingest ll2 previous` | |
| ingest_swpc | */15 * * * * | `bin/ingest swpc` | Space weather |
| ingest_satnogs | 0 3 * * 0 | `bin/ingest satnogs` | Weekly freqs |
| ingest_ground_stations | 0 3 1 * * | `bin/ingest ground-stations` | Monthly |
| aggregate_stats | 30 4 * * * | `bin/aggregate stats` | Daily rollups |
| archive_tle_history | 0 5 * * 0 | `bin/archive tle-history` | Weekly |
| generate_og_images | every 30 min | `bin/generate og-images --dirty` | Re-render changed |
| sitemap_regenerate | 0 6 * * * | `bin/generate sitemap` | Daily |
| health_check | * * * * * | `bin/health` | Per-minute |

---

## 24. Error Handling & Degraded Modes

**Source outage handling:**
- Each ingester tracks last successful run timestamp
- If a source is >2 cycles late, mark its data "stale" with age badge in UI
- Critical outage (>24h): graceful degradation banner with cause + link to status page

**Bad data:**
- TLE validation: checksum, line lengths, epoch within reasonable bounds
- Skip and log invalid records
- Alert (email/Discord webhook) if >1% of a feed rejected in one run

**Rate limits:**
- Exponential backoff on 429
- Queued retry for Space-Track historical fetches
- Hard cap on N2YO daily quota; degrade to local SGP4 + estimated magnitude

**Frontend errors:**
- SGP4 propagation failure → display "?" placeholder, log to Sentry
- WebGL unavailable → static fallback page with text-only catalog browser
- Slow connection detected → prompt to switch to lite mode

---

## 25. Security & Operations

- All ingester credentials in `.env` (not in VCS); use `vlucas/phpdotenv`
- Rate limit public API: 60 req/min per IP, 1000/hr (token bucket via Redis)
- WAF in front (Cloudflare); SQL injection protection via prepared statements (paranoid even though Slim doesn't expose raw SQL by default)
- HTTPS-only, HSTS, CSP headers
- Subresource integrity for Cesium CDN (or self-host)
- No tracking pixels, no third-party fonts (self-host); minimal privacy footprint
- Logs scrubbed of IP after 30 days
- `/privacy` and `/terms` pages required

---

## 26. Public API & Documentation

Even with no user accounts, the API is public — third parties may want to build on it. Document at `/api`:
- OpenAPI 3.0 spec
- Interactive Swagger UI
- Code examples in curl / Python / JavaScript / PHP
- "How is this data generated?" provenance page citing every source
- Attribution requirements per source (CelesTrak prefers credit, Space-Track has explicit terms, LL2 requires attribution)
- Status page (`/status`) showing freshness of each source

---

## 27. Branding & Visual Identity

- Color palette: deep space navy (#0a0e27), accent cyan (#00d9ff), warning amber (#ffb700), danger red (#ff3860), success green (#23d160)
- Typography: a clean monospace for data (JetBrains Mono / IBM Plex Mono), a humanist sans for UI (Inter)
- Logo: stylized orbit ellipse with a dot, matching trackr.live family aesthetic
- Dark mode default; light mode optional but secondary (space looks wrong in light mode)
- Subtle micro-interactions: orbit lines fade in, panels slide with eased curves
- Loading state: animated globe wireframe while Cesium initializes

---

## 28. Phased Build Plan (suggested)

**Phase 1 — Foundation (MVP)**
- Schema + ingesters for CelesTrak only
- PHP API for /satellites, /satellites/{norad}, /satellites/{norad}/tle
- Cesium globe with point primitives, basic selection
- Object detail panel with TLE + orbital elements + current state
- Search by name and NORAD ID
- Basic time scrubbing (±48h)

**Phase 2 — Data depth**
- Space-Track ingester (decay/TIP)
- Launch Library 2 ingester
- Launches view + countdown
- Reentries view
- Pass predictions (server-side calc + N2YO for visual mag)
- Observer location handling

**Phase 3 — Showcase visuals**
- Day/night terminator polish, sun/moon/stars
- Orbit ribbons for selected objects
- 3D models for ISS / Tiangong / Hubble
- Ground stations + sensor cones
- Light pollution overlay

**Phase 4 — Situational awareness**
- SOCRATES conjunctions
- Space weather widget + aurora overlay
- Stats dashboard
- Event feed + RSS

**Phase 5 — Polish & ecosystem**
- AMSAT/SatNOGS radio info
- Mobile PWA optimization
- OpenAPI docs
- OG image generation
- Sitemap, SEO
- Sharing deep links

---

## 29. Open Questions / Decisions Pending

1. **PHP framework:** Slim 4 vs raw PHP with custom router? Slim is recommended for routing/middleware ergonomics.
RESPONSE: Slim 4

2. **ORM:** Doctrine, Eloquent (standalone via `illuminate/database`), or raw PDO? Recommend Eloquent standalone for speed of development.
RESPONSE: ORM with a sqlite db

3. **Cesium asset hosting:** Cesium ion (free tier, requires token) for high-res imagery, or self-host Natural Earth + Bing? Recommend ion free tier initially.
RESPONSE: Cesium ion free tier

4. **Browser SGP4 vs server:** all in browser is simplest; for very heavy clients, server-side state vector endpoint exists as fallback. Confirm browser-first.
RESPONSE: Browser first.  Backend just suplies data

5. **Historical TLE backfill:** import full Space-Track archive (multi-GB), or lazy-fetch on demand? Recommend lazy + aggressive cache.
RESPONSE: Lazy + aggressive cache

6. **3D model sourcing:** NASA 3D Resources is the canonical source for ISS, Hubble, etc. (public domain). Vehicle models from SpaceX/etc. need to be original or licensed.
RESPONSE:  NASA 3d Resources or other public domain models.

7. **Analytics:** Plausible/Umami (privacy-respecting), or none? Recommend Plausible for catalog popularity stats.
RESPONSE: Plausible

---

## 30. Glossary

- **TLE** — Two-Line Element set; classic orbital data format
- **OMM** — Orbit Mean-elements Message; modern CCSDS format replacing TLE
- **GP data** — General Perturbations orbital data (TLE/OMM family)
- **SGP4** — Simplified General Perturbations 4; the standard TLE propagator
- **NORAD ID** — catalog number assigned by US Space Force
- **Intl Designator / COSPAR ID** — YYYY-NNNAAA, assigned at launch
- **TCA** — Time of Closest Approach (conjunctions)
- **TIP** — Tracking and Impact Prediction message (reentries)
- **RCS** — Radar Cross-Section; rough size proxy
- **AOS / LOS** — Acquisition / Loss of Signal (pass start/end above horizon)
- **SSO** — Sun-Synchronous Orbit
- **GTO** — Geostationary Transfer Orbit
- **18 SPCS / CSpOC** — US Space Force units that catalog and publish orbital data