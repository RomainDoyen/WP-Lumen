# URLs queue — prod hardening (1.3.47)

## Drivers
1. Tools page AJAX poll (1 tick / ~1.8 s, timeout 25 s)
2. admin-post start / tick / stop (no JS)
3. WP-Cron optional

## Safety
- Tick budget 25 s, lock TTL 45 s
- No COUNT at start
- try/catch per attachment — skip & continue
- Stale recovery after 120 s
- SQL LIMITs reduced (1500/1500/800)
- Elementor CSS on disk rewritten per pair
- Elementor cache cleared once at finalize
- Errors list + last_error in UI
- Plugin version shown on Tools panel
