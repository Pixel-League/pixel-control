/**
 * Interfaces for Elite mode turn context and turn summary data.
 * These types mirror the plugin's elite_context payload shape and the elite_turn_summary event.
 */

export interface EliteContext {
  turn_number: number;
  attacker_login: string;
  defender_logins: string[];
  attacker_team_id: number | null;
  phase: string | null;
}

export interface EliteTurnPlayerStats {
  kills: number;
  deaths: number;
  hits: number;
  shots: number;
  misses: number;
  rocket_hits: number;
}

export interface EliteClutchInfo {
  is_clutch: boolean;
  clutch_player_login: string | null;
  alive_defenders_at_end: number;
  total_defenders: number;
}

export interface EliteTurnSummary {
  event_kind: 'elite_turn_summary';
  turn_number: number;
  attacker_login: string;
  defender_logins: string[];
  attacker_team_id: number | null;
  outcome: string;
  duration_seconds: number;
  defense_success: boolean;
  per_player_stats: Record<string, EliteTurnPlayerStats>;
  map_uid: string;
  map_name: string;
  clutch: EliteClutchInfo;
}
