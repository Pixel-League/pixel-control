import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';

export type BracketMatchStatus =
  | 'pending'
  | 'scheduled'
  | 'in_progress'
  | 'completed';

export type BracketTeamOutcome = 'winner' | 'loser';
export type BracketRouteSlot = 'top' | 'center' | 'bottom';

export interface BracketTeamSlot {
  teamId?: string;
  name?: string | null;
  seed?: number;
  score?: number | null;
  outcome?: BracketTeamOutcome;
}

export interface BracketMatch {
  id: string;
  label: string;
  status: BracketMatchStatus;
  teams: [BracketTeamSlot, BracketTeamSlot];
  nextMatchId?: string;
  nextSlot?: BracketRouteSlot;
}

export interface BracketRound {
  id: string;
  title: string;
  matches: BracketMatch[];
}

export interface BracketRankingBadge {
  label: '1st' | '2nd';
  teamName: string;
}

export interface BracketGrandFinal {
  title?: string;
  match: BracketMatch;
  rankings?: {
    first?: BracketRankingBadge;
    second?: BracketRankingBadge;
  };
}

export interface BracketData {
  winnerRounds: BracketRound[];
  loserRounds: BracketRound[];
  grandFinal: BracketGrandFinal;
}

export interface BracketProps {
  data: BracketData;
  theme?: Theme;
  className?: string;
  showConnectors?: boolean;
}

type BracketSectionId = 'winner' | 'loser';

interface BracketConnectorLink {
  sourceMatchId: string;
  targetMatchId: string;
  nextSlot: BracketRouteSlot;
}

interface BracketConnectorPath extends BracketConnectorLink {
  id: string;
  path: string;
}

interface OverlaySize {
  width: number;
  height: number;
}

const matchStatusLabels: Record<BracketMatchStatus, string> = {
  pending: 'Pending',
  scheduled: 'Scheduled',
  in_progress: 'In Progress',
  completed: 'Completed',
};

const statusToneByTheme: Record<Theme, Record<BracketMatchStatus, string>> = {
  dark: {
    pending: 'bg-nm-dark-s text-px-label border border-white/[0.08]',
    scheduled: 'bg-px-primary/15 text-px-primary-light border border-px-primary/40',
    in_progress: 'bg-px-warning/20 text-px-warning border border-px-warning/50',
    completed: 'bg-px-success/20 text-px-success border border-px-success/50',
  },
  light: {
    pending: 'bg-nm-light-s text-px-label border border-black/[0.08]',
    scheduled: 'bg-px-primary/10 text-px-primary-dark border border-px-primary/35',
    in_progress: 'bg-px-warning/20 text-px-offblack border border-px-warning/45',
    completed: 'bg-px-success/20 text-px-offblack border border-px-success/45',
  },
};

const rankingToneByTheme: Record<Theme, Record<'1st' | '2nd', string>> = {
  dark: {
    '1st': 'bg-px-success/20 text-px-success border border-px-success/50',
    '2nd': 'bg-px-warning/20 text-px-warning border border-px-warning/50',
  },
  light: {
    '1st': 'bg-px-success/20 text-px-offblack border border-px-success/45',
    '2nd': 'bg-px-warning/20 text-px-offblack border border-px-warning/45',
  },
};

const BRACKET_MATCH_CARD_HEIGHT = 132;
const BRACKET_MATCH_VERTICAL_GAP = 16;
const BRACKET_MATCH_MIN_GAP = 14;

interface BracketSectionLayout {
  boardHeight: number;
  matchTops: Record<string, number>;
}

function getSlotAnchorRatio(slot?: BracketRouteSlot): number {
  if (slot === 'top') return 0.3;
  if (slot === 'bottom') return 0.7;
  return 0.5;
}

function buildSectionLayout(rounds: BracketRound[]): BracketSectionLayout {
  if (rounds.length === 0) {
    return {
      boardHeight: BRACKET_MATCH_CARD_HEIGHT,
      matchTops: {},
    };
  }

  const matchTops: Record<string, number> = {};
  const incomingByTarget = new Map<
    string,
    Array<{ sourceMatchId: string; nextSlot: BracketRouteSlot | undefined }>
  >();

  rounds.forEach((round) => {
    round.matches.forEach((match) => {
      if (!match.nextMatchId) return;

      const links = incomingByTarget.get(match.nextMatchId) ?? [];
      links.push({
        sourceMatchId: match.id,
        nextSlot: match.nextSlot,
      });
      incomingByTarget.set(match.nextMatchId, links);
    });
  });

  const firstRound = rounds[0];
  firstRound.matches.forEach((match, index) => {
    matchTops[match.id] = index * (BRACKET_MATCH_CARD_HEIGHT + BRACKET_MATCH_VERTICAL_GAP);
  });

  const firstRoundHeight = Math.max(
    BRACKET_MATCH_CARD_HEIGHT,
    firstRound.matches.length * (BRACKET_MATCH_CARD_HEIGHT + BRACKET_MATCH_VERTICAL_GAP) -
      BRACKET_MATCH_VERTICAL_GAP,
  );

  for (let roundIndex = 1; roundIndex < rounds.length; roundIndex += 1) {
    const round = rounds[roundIndex];
    if (round.matches.length === 0) continue;

    const fallbackStep =
      round.matches.length > 1
        ? (firstRoundHeight - BRACKET_MATCH_CARD_HEIGHT) / (round.matches.length - 1)
        : 0;

    let previousBottom = Number.NEGATIVE_INFINITY;

    round.matches.forEach((match, matchIndex) => {
      const incoming = incomingByTarget.get(match.id) ?? [];
      const impliedTops = incoming
        .map((link) => {
          const sourceTop = matchTops[link.sourceMatchId];
          if (typeof sourceTop !== 'number') return null;

          const sourceCenter = sourceTop + BRACKET_MATCH_CARD_HEIGHT / 2;
          return sourceCenter - BRACKET_MATCH_CARD_HEIGHT * getSlotAnchorRatio(link.nextSlot);
        })
        .filter((value): value is number => typeof value === 'number');

      let nextTop =
        impliedTops.length > 0
          ? impliedTops.reduce((sum, value) => sum + value, 0) / impliedTops.length
          : round.matches.length > 1
            ? matchIndex * fallbackStep
            : (firstRoundHeight - BRACKET_MATCH_CARD_HEIGHT) / 2;

      if (!Number.isFinite(nextTop)) {
        nextTop = matchIndex * (BRACKET_MATCH_CARD_HEIGHT + BRACKET_MATCH_VERTICAL_GAP);
      }

      if (previousBottom !== Number.NEGATIVE_INFINITY) {
        nextTop = Math.max(nextTop, previousBottom + BRACKET_MATCH_MIN_GAP);
      }

      matchTops[match.id] = nextTop;
      previousBottom = nextTop + BRACKET_MATCH_CARD_HEIGHT;
    });
  }

  const tops = Object.values(matchTops);
  const minTop = tops.length > 0 ? Math.min(...tops) : 0;

  if (minTop < 0) {
    const shift = Math.abs(minTop);
    Object.keys(matchTops).forEach((matchId) => {
      matchTops[matchId] += shift;
    });
  }

  const maxBottom = Math.max(
    firstRoundHeight,
    ...Object.values(matchTops).map((top) => top + BRACKET_MATCH_CARD_HEIGHT),
  );

  return {
    boardHeight: Math.ceil(maxBottom),
    matchTops,
  };
}

function getDisplayTeamName(name?: string | null): string {
  const trimmedName = name?.trim();
  return trimmedName && trimmedName.length > 0 ? trimmedName : 'TBD';
}

function getDisplayScore(score?: number | null): string {
  return score === 0 || score ? String(score) : '-';
}

function buildConnectorLinks(rounds: BracketRound[]): BracketConnectorLink[] {
  const matchIds = new Set(rounds.flatMap((round) => round.matches.map((match) => match.id)));
  const links: BracketConnectorLink[] = [];

  rounds.forEach((round) => {
    round.matches.forEach((match) => {
      if (!match.nextMatchId || !matchIds.has(match.nextMatchId)) return;
      links.push({
        sourceMatchId: match.id,
        targetMatchId: match.nextMatchId,
        nextSlot: match.nextSlot ?? 'center',
      });
    });
  });

  return links;
}

interface TeamRowProps {
  matchId: string;
  teamIndex: 0 | 1;
  team: BracketTeamSlot;
  isDark: boolean;
}

function TeamRow({ matchId, teamIndex, team, isDark }: TeamRowProps) {
  const displayName = getDisplayTeamName(team.name);
  const label =
    team.seed || team.seed === 0
      ? `#${team.seed} ${displayName}`
      : displayName;
  const rowToneClass =
    team.outcome === 'winner'
      ? 'bg-px-success/10'
      : team.outcome === 'loser'
        ? 'bg-px-error/10'
        : undefined;
  const scoreToneClass =
    team.outcome === 'winner'
      ? 'text-px-success'
      : team.outcome === 'loser'
        ? 'text-px-error'
        : isDark
          ? 'text-px-white'
          : 'text-px-offblack';

  return (
    <div
      className={cn(
        'h-[46px] grid grid-cols-[minmax(0,1fr)_56px] items-stretch border-b last:border-b-0',
        isDark ? 'border-white/[0.08]' : 'border-black/[0.08]',
        rowToneClass,
      )}
    >
      <div className="min-w-0 px-3 py-2 flex items-center">
        <p
          className={cn(
            'font-body text-xs uppercase tracking-wide-body truncate',
            isDark ? 'text-px-white' : 'text-px-offblack',
            displayName === 'TBD' && 'text-px-label',
          )}
          title={label}
        >
          {label}
        </p>
      </div>
      <div
        data-testid={`bracket-score-${matchId}-${teamIndex}`}
        className={cn(
          'h-full border-l px-2 py-2 flex items-center justify-center text-center',
          'font-display text-2xl leading-none tracking-display',
          isDark ? 'border-white/[0.08]' : 'border-black/[0.08]',
          scoreToneClass,
        )}
      >
        {getDisplayScore(team.score)}
      </div>
    </div>
  );
}

interface MatchCardProps {
  match: BracketMatch;
  isDark: boolean;
  onMount?: (matchId: string, element: HTMLDivElement | null) => void;
}

function MatchCard({ match, isDark, onMount }: MatchCardProps) {
  return (
    <div
      data-testid={`bracket-match-${match.id}`}
      data-match-id={match.id}
      ref={
        onMount
          ? (element) => {
              onMount(match.id, element);
            }
          : undefined
      }
      className={cn(
        'h-[132px] overflow-hidden',
        isDark
          ? 'bg-nm-dark shadow-nm-raised-d border border-white/[0.08]'
          : 'bg-nm-light shadow-nm-raised-l border border-black/[0.08]',
      )}
    >
      <div
        className={cn(
          'h-10 flex items-center justify-between gap-2 px-3 border-b',
          isDark ? 'border-white/[0.08] bg-nm-dark-s' : 'border-black/[0.08] bg-nm-light-s',
        )}
      >
        <p
          className={cn(
            'font-display text-base uppercase tracking-display font-bold leading-6',
            isDark ? 'text-px-white' : 'text-px-offblack',
          )}
        >
          {match.label}
        </p>
        <span
          className={cn(
            'px-2 py-1 font-body text-[10px] font-semibold uppercase tracking-wide-body',
            statusToneByTheme[isDark ? 'dark' : 'light'][match.status],
          )}
        >
          {matchStatusLabels[match.status]}
        </span>
      </div>
      <div>
        <TeamRow matchId={match.id} teamIndex={0} team={match.teams[0]} isDark={isDark} />
        <TeamRow matchId={match.id} teamIndex={1} team={match.teams[1]} isDark={isDark} />
      </div>
    </div>
  );
}

interface RoundColumnProps {
  round: BracketRound;
  isDark: boolean;
  boardHeight: number;
  matchTops: Record<string, number>;
  registerMatchRef: (matchId: string, element: HTMLDivElement | null) => void;
}

function RoundColumn({
  round,
  isDark,
  boardHeight,
  matchTops,
  registerMatchRef,
}: RoundColumnProps) {
  return (
    <div className="w-[264px] shrink-0">
      <p
        className={cn(
          'font-display text-lg font-bold uppercase tracking-display',
          isDark ? 'text-px-white' : 'text-px-offblack',
        )}
      >
        {round.title}
      </p>
      <div className="relative mt-3" style={{ height: `${boardHeight}px` }}>
        {round.matches.map((match) => (
          <div
            key={match.id}
            className="absolute left-0 right-0"
            style={{ top: `${Math.round(matchTops[match.id] ?? 0)}px` }}
          >
            <MatchCard
              match={match}
              isDark={isDark}
              onMount={registerMatchRef}
            />
          </div>
        ))}
      </div>
    </div>
  );
}

interface BracketRoundsSectionProps {
  sectionId: BracketSectionId;
  title: string;
  rounds: BracketRound[];
  isDark: boolean;
  showConnectors: boolean;
}

function BracketRoundsSection({
  sectionId,
  title,
  rounds,
  isDark,
  showConnectors,
}: BracketRoundsSectionProps) {
  const boardRef = useRef<HTMLDivElement | null>(null);
  const matchRefs = useRef<Record<string, HTMLDivElement | null>>({});
  const [connectors, setConnectors] = useState<BracketConnectorPath[]>([]);
  const [overlaySize, setOverlaySize] = useState<OverlaySize>({ width: 1, height: 1 });
  const sectionLayout = useMemo(() => buildSectionLayout(rounds), [rounds]);
  const connectorLinks = useMemo(() => buildConnectorLinks(rounds), [rounds]);

  const registerMatchRef = useCallback((matchId: string, element: HTMLDivElement | null) => {
    matchRefs.current[matchId] = element;
  }, []);

  const recomputeConnectors = useCallback(() => {
    const boardElement = boardRef.current;
    if (!boardElement || !showConnectors) {
      setConnectors([]);
      return;
    }

    const boardRect = boardElement.getBoundingClientRect();
    const width = Math.max(boardRect.width, 1);
    const height = Math.max(boardRect.height, 1);

    setOverlaySize((current) =>
      current.width === width && current.height === height ? current : { width, height },
    );

    const nextConnectors: BracketConnectorPath[] = [];

    connectorLinks.forEach((link) => {
      const sourceElement = matchRefs.current[link.sourceMatchId];
      const targetElement = matchRefs.current[link.targetMatchId];
      if (!sourceElement || !targetElement) return;

      const sourceRect = sourceElement.getBoundingClientRect();
      const targetRect = targetElement.getBoundingClientRect();

      const startX = sourceRect.right - boardRect.left;
      const startY = sourceRect.top + sourceRect.height / 2 - boardRect.top;
      const endX = targetRect.left - boardRect.left;
      const targetAnchorRatio = getSlotAnchorRatio(link.nextSlot);
      const endY = targetRect.top + targetRect.height * targetAnchorRatio - boardRect.top;
      const elbowOffset = Math.max(18, Math.abs(endX - startX) / 2);
      const elbowX = startX + elbowOffset;

      nextConnectors.push({
        ...link,
        id: `${link.sourceMatchId}-${link.targetMatchId}`,
        path: `M ${startX} ${startY} L ${elbowX} ${startY} L ${elbowX} ${endY} L ${endX} ${endY}`,
      });
    });

    setConnectors(nextConnectors);
  }, [connectorLinks, showConnectors]);

  useLayoutEffect(() => {
    recomputeConnectors();
  }, [recomputeConnectors, rounds]);

  useEffect(() => {
    if (!showConnectors) {
      setConnectors([]);
    }
  }, [showConnectors]);

  useEffect(() => {
    if (!showConnectors) return;

    const boardElement = boardRef.current;
    if (!boardElement) return;

    let frameId = 0;

    const scheduleRecompute = () => {
      if (frameId) {
        cancelAnimationFrame(frameId);
      }
      frameId = requestAnimationFrame(() => {
        recomputeConnectors();
      });
    };

    scheduleRecompute();

    let observer: ResizeObserver | undefined;
    if (typeof ResizeObserver !== 'undefined') {
      observer = new ResizeObserver(() => {
        scheduleRecompute();
      });

      observer.observe(boardElement);
      Object.values(matchRefs.current).forEach((matchElement) => {
        if (matchElement) {
          observer?.observe(matchElement);
        }
      });
    }

    window.addEventListener('resize', scheduleRecompute);

    return () => {
      if (frameId) {
        cancelAnimationFrame(frameId);
      }
      window.removeEventListener('resize', scheduleRecompute);
      observer?.disconnect();
    };
  }, [recomputeConnectors, showConnectors, rounds]);

  return (
    <section
      data-testid={`bracket-section-${sectionId}`}
      className={cn(
        'p-4 md:p-5',
        isDark
          ? 'bg-nm-dark-s shadow-nm-flat-d border border-white/[0.08]'
          : 'bg-nm-light-s shadow-nm-flat-l border border-black/[0.08]',
      )}
    >
      <div className="mb-4 flex items-center justify-between gap-3">
        <h2
          className={cn(
            'font-display text-xl font-bold uppercase tracking-display',
            isDark ? 'text-px-white' : 'text-px-offblack',
          )}
        >
          {title}
        </h2>
        <p className="font-body text-xs uppercase tracking-wide-body text-px-label">
          {rounds.length} rounds
        </p>
      </div>

      <div data-testid={`bracket-scroll-${sectionId}`} className="overflow-x-auto pb-2">
        <div ref={boardRef} className="relative min-w-max">
          {showConnectors ? (
            <svg
              className="pointer-events-none absolute inset-0 z-0"
              width={overlaySize.width}
              height={overlaySize.height}
              viewBox={`0 0 ${overlaySize.width} ${overlaySize.height}`}
              preserveAspectRatio="none"
              aria-hidden="true"
            >
              {connectors.map((connector) => (
                <path
                  key={connector.id}
                  data-testid={`bracket-connector-${connector.id}`}
                  data-source-match-id={connector.sourceMatchId}
                  data-target-match-id={connector.targetMatchId}
                  d={connector.path}
                  fill="none"
                  stroke={isDark ? '#7B7FA0' : '#6E7191'}
                  strokeWidth={1.5}
                  strokeOpacity={0.8}
                  vectorEffect="non-scaling-stroke"
                />
              ))}
            </svg>
          ) : null}

          <div
            className={cn(
              'relative z-10 flex items-start pr-3',
              showConnectors ? 'gap-7' : 'gap-4',
            )}
          >
            {rounds.map((round) => (
              <RoundColumn
                key={round.id}
                round={round}
                isDark={isDark}
                boardHeight={sectionLayout.boardHeight}
                matchTops={sectionLayout.matchTops}
                registerMatchRef={registerMatchRef}
              />
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}

interface RankingBadgeProps {
  rank: '1st' | '2nd';
  teamName: string;
  isDark: boolean;
}

function RankingBadge({ rank, teamName, isDark }: RankingBadgeProps) {
  const displayName = getDisplayTeamName(teamName);
  const tone = rankingToneByTheme[isDark ? 'dark' : 'light'][rank];

  return (
    <div
      data-testid={`bracket-ranking-${rank}`}
      className={cn(
        'flex items-center gap-2 px-3 py-2 border',
        'font-body text-xs font-semibold uppercase tracking-wide-body',
        tone,
      )}
    >
      <span className="font-display text-lg leading-5 tracking-display">{rank}</span>
      <span className="truncate max-w-[220px]" title={displayName}>{displayName}</span>
    </div>
  );
}

interface GrandFinalSectionProps {
  final: BracketGrandFinal;
  isDark: boolean;
}

function GrandFinalSection({ final, isDark }: GrandFinalSectionProps) {
  return (
    <section
      data-testid="bracket-section-grand-final"
      className={cn(
        'p-4 md:p-5',
        isDark
          ? 'bg-nm-dark-s shadow-nm-flat-d border border-white/[0.08]'
          : 'bg-nm-light-s shadow-nm-flat-l border border-black/[0.08]',
      )}
    >
      <div className="mb-4">
        <h2
          className={cn(
            'font-display text-xl font-bold uppercase tracking-display',
            isDark ? 'text-px-white' : 'text-px-offblack',
          )}
        >
          {final.title ?? 'Grand Final'}
        </h2>
      </div>

      <div className="grid gap-4 lg:grid-cols-[340px_minmax(0,1fr)] items-start">
        <MatchCard match={final.match} isDark={isDark} />

        <div className="flex flex-wrap gap-3" data-testid="bracket-ranking-wrapper">
          {final.rankings?.first ? (
            <RankingBadge
              rank="1st"
              teamName={final.rankings.first.teamName}
              isDark={isDark}
            />
          ) : null}
          {final.rankings?.second ? (
            <RankingBadge
              rank="2nd"
              teamName={final.rankings.second.teamName}
              isDark={isDark}
            />
          ) : null}
        </div>
      </div>
    </section>
  );
}

export function Bracket({
  data,
  theme: themeProp,
  className,
  showConnectors = true,
}: BracketProps) {
  const contextTheme = useThemeOptional();
  const theme = themeProp ?? contextTheme;
  const isDark = theme === 'dark';

  return (
    <div data-testid="bracket-root" className={cn('w-full space-y-6', className)}>
      <BracketRoundsSection
        sectionId="winner"
        title="Winner Bracket"
        rounds={data.winnerRounds}
        isDark={isDark}
        showConnectors={showConnectors}
      />

      <BracketRoundsSection
        sectionId="loser"
        title="Loser Bracket"
        rounds={data.loserRounds}
        isDark={isDark}
        showConnectors={showConnectors}
      />

      <GrandFinalSection final={data.grandFinal} isDark={isDark} />
    </div>
  );
}
