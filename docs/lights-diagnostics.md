# Lights diagnostic log

Open `storage/logs/lights-YYYY-MM-DD.log` on the server handling the Lights worker. Laravel rotates this dedicated log daily and retains 30 days. `LIGHTS_LOG_LEVEL` must be `info` to retain the switching sequence; higher levels hide normal requests and successful transitions.

Successful background HTTP 200 status polls are silent. Previously, a poll about every 20 seconds produced roughly 4,320 routine success entries per day. Unsuccessful status responses, transport/parsing failures and switching request/confirmation/lifecycle evidence are still logged. Poll frequency and safety checks are unchanged.

Search by `session_id` or `command_id`, then follow the nearby Shelly entries for that channel. Session events record reservation, start dispatch, confirmed ON timer, stop request, OFF dispatch, completion, interrupted commands, uncertainty, review and release. Manual controls record queue, dispatch and result. Background status and worker failures are also recorded.

Shelly entries show `stage` (`status` or `switch`), requested ON/OFF, HTTP status, elapsed milliseconds, cURL error number, and safe output/timer evidence. They exclude keys, URLs, device identifiers, raw provider bodies, personal information and arbitrary exception text.

Common evidence:

| Evidence | Meaning |
| --- | --- |
| HTTP 401/403 | Provider rejected authorization |
| HTTP 429 | Provider rate limit |
| cURL 6 | DNS resolution failed |
| cURL 7 | Connection failed |
| cURL 28 | Request timed out |
| cURL 60/77 | TLS certificate/CA validation failed |
| cURL 23 | Response could not be read or exceeded the response limit |
| ON confirmation with missing/wrong timer | ON acknowledged but required fresh timer evidence was unavailable |
| Review after OFF acknowledgement | Earlier uncertainty or supervised control still requires physical confirmation |

A timeout cannot establish whether Shelly applied the switch. Do not repeat ON based on the log alone. Preserve the safety review and check the physical court. Logs diagnose the failure stage; they do not prove physical illumination.

Logging does not change switching, billing, retry or release rules. A log-write failure cannot interrupt safety processing. If expected entries are absent, check server log-directory permissions and the configured Lights log level. After deploying code, refresh cached configuration where needed and restart any long-running workers using the normal release procedure.

## Command timeout handling

Timer confirmation allows a bounded ten-second clock difference in either direction between Shelly and the application server. The October 26 log showed fault-free ON with the correct 14,400-second timer, but a timer timestamp about three seconds ahead of the server was rejected by the former two-second upper limit, causing safety OFF. The output and duration checks, stale/future timestamp rejection outside the allowance, original server cutoff and no-ON-retry rules remain enforced. This is a clock-tolerance correction, not evidence of Wi-Fi failure or physical illumination.

Customer starts and takeovers (including an administrator starting for a member) now require a new read-only Shelly status check before session reservation. The server validates access and availability, releases the database lock for the status request, then validates again before creating the session. Offline, unavailable, stale, incomplete, faulty or unexpected channel status blocks the start without charging or changing other session deadlines. Existing request keys remain idempotent.

Customer safety recovery is automatic. After the 30-second in-flight-command window, fresh online fault-free OFF evidence releases the session and reservation. Fresh ON evidence queues another safety OFF for customer sessions only; the next worker tick dispatches it, with at least a new 30-second window after another uncertain result. Billing remains frozen and ON is never replayed. A steady running session on another court does not block recovery polling. Offline, faulty, missing or stale evidence keeps the reservation until communication recovers. Supervised pilot OFF retries retain their operator gate.

The customer sees “Checking connection…” while waiting. Connection failures ask the facility to check controller power, Wi-Fi and internet and explicitly say no session was started. This warning remains visible through background page refreshes. Cloud reachability does not independently diagnose the Wi-Fi/router; it also cannot prove physical illumination. The worker still performs its own preflight and confirms output and the safety timer before billing begins.

Switch acknowledgements now have up to 10 seconds, while each status request retains a 4-second limit. The complete control operation has a 25-second budget, below the session engine's 30-second interrupted-command window. Every request is capped by the remaining budget; ON is still sent once and requires an acknowledgement plus fresh output/timer evidence before billing.

If an OFF acknowledgement is lost, the client checks status up to three times without resending OFF. Only online, fault-free OFF evidence resolves it. Offline cached output cannot release an uncertain command. The local emergency-OFF helper allows 28 seconds so it does not kill the bounded confirmation operation prematurely.

Provider rejection logs include only documented error codes, such as `DEVICE_OFFLINE`; unrecognized response text remains private. These changes address premature timeout and lost OFF acknowledgement handling. They cannot repair the device's power, router, network or Shelly cloud connection. An offline device must recover or be checked on site.
