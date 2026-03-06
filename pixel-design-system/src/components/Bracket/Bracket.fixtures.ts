import type { BracketData } from './Bracket';

export const completedBracketFixture: BracketData = {
  winnerRounds: [
    {
      id: 'winner-round-1',
      title: 'Winner R1',
      matches: [
        {
          id: 'w-r1-m1',
          label: 'W-M1',
          status: 'completed',
          teams: [
            { teamId: 'owned', name: 'OWNED', seed: 1, score: 2, outcome: 'winner' },
            { teamId: 'crystal', name: 'CRYSTAL', seed: 8, score: 0, outcome: 'loser' },
          ],
          nextMatchId: 'w-r2-m1',
          nextSlot: 'top',
        },
        {
          id: 'w-r1-m2',
          label: 'W-M2',
          status: 'completed',
          teams: [
            { teamId: 'lunar-core', name: 'LUNAR CORE', seed: 4, score: 1, outcome: 'loser' },
            { teamId: 'eden-force', name: 'EDEN FORCE', seed: 5, score: 2, outcome: 'winner' },
          ],
          nextMatchId: 'w-r2-m1',
          nextSlot: 'bottom',
        },
        {
          id: 'w-r1-m3',
          label: 'W-M3',
          status: 'completed',
          teams: [
            { teamId: 'neon-drift', name: 'NEON DRIFT', seed: 2, score: 0, outcome: 'loser' },
            { teamId: 'vortex-prime', name: 'VORTEX PRIME', seed: 7, score: 2, outcome: 'winner' },
          ],
          nextMatchId: 'w-r2-m2',
          nextSlot: 'top',
        },
        {
          id: 'w-r1-m4',
          label: 'W-M4',
          status: 'completed',
          teams: [
            { teamId: 'atlas-unit', name: 'ATLAS UNIT', seed: 3, score: 2, outcome: 'winner' },
            { teamId: 'rogue-signal', name: 'ROGUE SIGNAL', seed: 6, score: 1, outcome: 'loser' },
          ],
          nextMatchId: 'w-r2-m2',
          nextSlot: 'bottom',
        },
      ],
    },
    {
      id: 'winner-round-2',
      title: 'Winner Semi',
      matches: [
        {
          id: 'w-r2-m1',
          label: 'W-M5',
          status: 'completed',
          teams: [
            { teamId: 'owned', name: 'OWNED', seed: 1, score: 2, outcome: 'winner' },
            { teamId: 'eden-force', name: 'EDEN FORCE', seed: 5, score: 1, outcome: 'loser' },
          ],
          nextMatchId: 'w-r3-m1',
          nextSlot: 'top',
        },
        {
          id: 'w-r2-m2',
          label: 'W-M6',
          status: 'completed',
          teams: [
            { teamId: 'vortex-prime', name: 'VORTEX PRIME', seed: 7, score: 0, outcome: 'loser' },
            { teamId: 'atlas-unit', name: 'ATLAS UNIT', seed: 3, score: 2, outcome: 'winner' },
          ],
          nextMatchId: 'w-r3-m1',
          nextSlot: 'bottom',
        },
      ],
    },
    {
      id: 'winner-round-3',
      title: 'Winner Final',
      matches: [
        {
          id: 'w-r3-m1',
          label: 'W-M7',
          status: 'completed',
          teams: [
            { teamId: 'owned', name: 'OWNED', seed: 1, score: 3, outcome: 'winner' },
            { teamId: 'atlas-unit', name: 'ATLAS UNIT', seed: 3, score: 2, outcome: 'loser' },
          ],
          nextMatchId: 'gf-1',
          nextSlot: 'top',
        },
      ],
    },
  ],
  loserRounds: [
    {
      id: 'loser-round-1',
      title: 'Loser R1',
      matches: [
        {
          id: 'l-r1-m1',
          label: 'L-M1',
          status: 'completed',
          teams: [
            { teamId: 'crystal', name: 'CRYSTAL', seed: 8, score: 2, outcome: 'winner' },
            { teamId: 'lunar-core', name: 'LUNAR CORE', seed: 4, score: 0, outcome: 'loser' },
          ],
          nextMatchId: 'l-r2-m1',
          nextSlot: 'top',
        },
        {
          id: 'l-r1-m2',
          label: 'L-M2',
          status: 'completed',
          teams: [
            { teamId: 'neon-drift', name: 'NEON DRIFT', seed: 2, score: 1, outcome: 'loser' },
            { teamId: 'rogue-signal', name: 'ROGUE SIGNAL', seed: 6, score: 2, outcome: 'winner' },
          ],
          nextMatchId: 'l-r2-m2',
          nextSlot: 'top',
        },
      ],
    },
    {
      id: 'loser-round-2',
      title: 'Loser R2',
      matches: [
        {
          id: 'l-r2-m1',
          label: 'L-M3',
          status: 'completed',
          teams: [
            { teamId: 'crystal', name: 'CRYSTAL', seed: 8, score: 1, outcome: 'loser' },
            { teamId: 'eden-force', name: 'EDEN FORCE', seed: 5, score: 2, outcome: 'winner' },
          ],
          nextMatchId: 'l-r3-m1',
          nextSlot: 'top',
        },
        {
          id: 'l-r2-m2',
          label: 'L-M4',
          status: 'completed',
          teams: [
            { teamId: 'rogue-signal', name: 'ROGUE SIGNAL', seed: 6, score: 2, outcome: 'winner' },
            { teamId: 'vortex-prime', name: 'VORTEX PRIME', seed: 7, score: 1, outcome: 'loser' },
          ],
          nextMatchId: 'l-r3-m1',
          nextSlot: 'bottom',
        },
      ],
    },
    {
      id: 'loser-round-3',
      title: 'Loser Semi',
      matches: [
        {
          id: 'l-r3-m1',
          label: 'L-M5',
          status: 'completed',
          teams: [
            { teamId: 'eden-force', name: 'EDEN FORCE', seed: 5, score: 2, outcome: 'winner' },
            { teamId: 'rogue-signal', name: 'ROGUE SIGNAL', seed: 6, score: 0, outcome: 'loser' },
          ],
          nextMatchId: 'l-r4-m1',
          nextSlot: 'top',
        },
      ],
    },
    {
      id: 'loser-round-4',
      title: 'Loser Final',
      matches: [
        {
          id: 'l-r4-m1',
          label: 'L-M6',
          status: 'completed',
          teams: [
            { teamId: 'eden-force', name: 'EDEN FORCE', seed: 5, score: 2, outcome: 'loser' },
            { teamId: 'atlas-unit', name: 'ATLAS UNIT', seed: 3, score: 3, outcome: 'winner' },
          ],
          nextMatchId: 'gf-1',
          nextSlot: 'bottom',
        },
      ],
    },
  ],
  grandFinal: {
    title: 'Grand Final',
    match: {
      id: 'gf-1',
      label: 'GF-M1',
      status: 'completed',
      teams: [
        { teamId: 'owned', name: 'OWNED', seed: 1, score: 3, outcome: 'winner' },
        { teamId: 'atlas-unit', name: 'ATLAS UNIT', seed: 3, score: 1, outcome: 'loser' },
      ],
    },
    rankings: {
      first: {
        label: '1st',
        teamName: 'OWNED',
      },
      second: {
        label: '2nd',
        teamName: 'ATLAS UNIT',
      },
    },
  },
};

export const inProgressBracketFixture: BracketData = {
  winnerRounds: [
    {
      id: 'in-progress-winner-round-1',
      title: 'Winner R1',
      matches: [
        {
          id: 'ip-w-r1-m1',
          label: 'W-M1',
          status: 'in_progress',
          teams: [
            {
              teamId: 'shootmania-academy-ultra-squad',
              name: 'SHOOTMANIA ACADEMY ULTRA SQUAD',
              seed: 1,
              score: 1,
            },
            { teamId: 'pixel-warriors', name: 'PIXEL WARRIORS', seed: 8, score: 1 },
          ],
          nextMatchId: 'ip-w-r2-m1',
          nextSlot: 'top',
        },
        {
          id: 'ip-w-r1-m2',
          label: 'W-M2',
          status: 'scheduled',
          teams: [
            { teamId: 'nova-eclipse', name: 'NOVA ECLIPSE', seed: 4 },
            { seed: 5 },
          ],
          nextMatchId: 'ip-w-r2-m1',
          nextSlot: 'bottom',
        },
      ],
    },
    {
      id: 'in-progress-winner-round-2',
      title: 'Winner Final',
      matches: [
        {
          id: 'ip-w-r2-m1',
          label: 'W-M3',
          status: 'pending',
          teams: [{ seed: 1 }, { seed: 4 }],
          nextMatchId: 'ip-gf-1',
          nextSlot: 'top',
        },
      ],
    },
  ],
  loserRounds: [
    {
      id: 'in-progress-loser-round-1',
      title: 'Loser R1',
      matches: [
        {
          id: 'ip-l-r1-m1',
          label: 'L-M1',
          status: 'scheduled',
          teams: [
            { teamId: 'pixel-warriors', name: 'PIXEL WARRIORS', seed: 8 },
            { teamId: 'tbd-team', name: null, seed: 5 },
          ],
          nextMatchId: 'ip-l-r2-m1',
          nextSlot: 'top',
        },
      ],
    },
    {
      id: 'in-progress-loser-round-2',
      title: 'Loser Final',
      matches: [
        {
          id: 'ip-l-r2-m1',
          label: 'L-M2',
          status: 'pending',
          teams: [{ seed: 8 }, { seed: 4 }],
          nextMatchId: 'ip-gf-1',
          nextSlot: 'bottom',
        },
      ],
    },
  ],
  grandFinal: {
    title: 'Grand Final',
    match: {
      id: 'ip-gf-1',
      label: 'GF-M1',
      status: 'scheduled',
      teams: [{ seed: 1 }, { seed: 4 }],
    },
  },
};
