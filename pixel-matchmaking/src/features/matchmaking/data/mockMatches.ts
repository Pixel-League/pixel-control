export interface MatchData {
  id: string;
  teamA: string;
  teamB: string;
  scoreA: number;
  scoreB: number;
  map: string;
  duration: string;
  mode: string;
}

export const mockMatches: MatchData[] = [
  {
    id: 'match-1',
    teamA: 'Pixel Strikers',
    teamB: 'Neon Wolves',
    scoreA: 2,
    scoreB: 1,
    map: 'Stadium A1',
    duration: '12:34',
    mode: 'Elite 3v3',
  },
  {
    id: 'match-2',
    teamA: 'Storm Phoenix',
    teamB: 'Dark Matter',
    scoreA: 0,
    scoreB: 3,
    map: 'Canyon Rush',
    duration: '08:17',
    mode: 'Elite 3v3',
  },
  {
    id: 'match-3',
    teamA: 'Iron Pulse',
    teamB: 'Apex Void',
    scoreA: 1,
    scoreB: 1,
    map: 'Valley Core',
    duration: '15:02',
    mode: 'Elite 3v3',
  },
];
