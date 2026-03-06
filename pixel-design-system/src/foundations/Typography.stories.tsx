import type { CSSProperties } from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { typography, typographyVariables, type TypographyStyleToken } from '@/tokens/tokens';
import { cn } from '@/utils/cn';

interface TypographyBoardProps {
  theme: Theme;
}

interface TypographySectionDefinition {
  id: string;
  title: string;
  description: string;
  sampleText: string;
  entries: Array<[string, TypographyStyleToken]>;
}

const typographyVariableStyles = Object.entries(typographyVariables).reduce<Record<string, string>>(
  (acc, [key, value]) => ({
    ...acc,
    [key]: value,
  }),
  {},
) as CSSProperties;

const textSections: TypographySectionDefinition[] = [
  {
    id: 'display',
    title: 'Display (Figma)',
    description: 'Display Huge, Large, Medium, Small, and X-Small mapped from frame 535:1886.',
    sampleText: 'The future is in our hands to shape.',
    entries: [
      ['huge', typography.display.huge],
      ['large', typography.display.large],
      ['medium', typography.display.medium],
      ['small', typography.display.small],
      ['xSmall', typography.display.xSmall],
    ],
  },
  {
    id: 'text',
    title: 'Text (Figma)',
    description: 'Text Large, Medium, Small, and X-Small mapped from frame 535:1886.',
    sampleText: 'The future is in our hands to shape.',
    entries: [
      ['large', typography.text.large],
      ['medium', typography.text.medium],
      ['small', typography.text.small],
      ['xSmall', typography.text.xSmall],
    ],
  },
  {
    id: 'body',
    title: 'Body (Design System)',
    description: 'Core body scale needed across form content, helper copy, and long text blocks.',
    sampleText: 'Final polish keeps softer shadows, stronger borders, and warmer labels.',
    entries: [
      ['regular', typography.body.regular],
      ['small', typography.body.small],
      ['xSmall', typography.body.xSmall],
      ['linkLarge', typography.body.linkLarge],
    ],
  },
  {
    id: 'ui',
    title: 'UI Utilities (Design System)',
    description: 'Extra styles required by components: labels, helper text, overlines, and button labels.',
    sampleText: 'Primary Action',
    entries: [
      ['label', typography.ui.label],
      ['labelStrong', typography.ui.labelStrong],
      ['helper', typography.ui.helper],
      ['buttonSm', typography.ui.buttonSm],
      ['buttonMd', typography.ui.buttonMd],
      ['buttonLg', typography.ui.buttonLg],
      ['overline', typography.ui.overline],
    ],
  },
];

function styleFromTypographyToken(style: TypographyStyleToken): CSSProperties {
  return {
    fontFamily: `var(${style.variable}-font-family)`,
    fontSize: `var(${style.variable}-size)`,
    fontWeight: `var(${style.variable}-weight)`,
    lineHeight: `var(${style.variable}-line-height)`,
    letterSpacing: `var(${style.variable}-letter-spacing)`,
    textTransform: `var(${style.variable}-text-transform)` as CSSProperties['textTransform'],
  };
}

function TypographyBoard({ theme }: TypographyBoardProps) {
  const resolvedTheme = useThemeOptional(theme);
  const isDark = resolvedTheme === 'dark';

  return (
    <div className="space-y-8" style={typographyVariableStyles}>
      <p className="font-body text-sm text-px-label">
        All text styles are tokenized and variableized. This board covers the full Figma text frame plus the
        additional UI styles required by the design system.
      </p>

      {textSections.map((section) => (
        <TypographySection key={section.id} section={section} isDark={isDark} />
      ))}
    </div>
  );
}

interface TypographySectionProps {
  section: TypographySectionDefinition;
  isDark: boolean;
}

function TypographySection({ section, isDark }: TypographySectionProps) {
  return (
    <section className="space-y-4">
      <div className="space-y-1">
        <h3 className="font-body text-sm font-semibold uppercase tracking-wide-body text-px-primary">{section.title}</h3>
        <p className="font-body text-xs text-px-label">{section.description}</p>
      </div>

      <div
        className={cn(
          'p-6 grid gap-4',
          isDark ? 'bg-nm-dark shadow-nm-raised-d border border-white/[0.08]' : 'bg-nm-light shadow-nm-raised-l border border-black/[0.08]',
        )}
      >
        {section.entries.map(([tokenName, spec]) => (
          <div key={tokenName} className="grid gap-1">
            <p
              style={styleFromTypographyToken(spec)}
              className={cn(isDark ? 'text-px-white' : 'text-px-offblack')}
            >
              {section.sampleText}
            </p>
            <p className="font-body text-xs text-px-label">
              {tokenName} - {spec.size} / {spec.weight} / line-height {spec.lineHeight} / letter-spacing{' '}
              {spec.letterSpacing} / transform {spec.textTransform} / {spec.variable}
            </p>
          </div>
        ))}
      </div>
    </section>
  );
}

const meta: Meta<typeof TypographyBoard> = {
  title: 'Foundations/Text',
  component: TypographyBoard,
  parameters: {
    layout: 'padded',
  },
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    theme: 'dark',
  },
};

export default meta;
type Story = StoryObj<typeof TypographyBoard>;

export const Dark: Story = {
  args: {
    theme: 'dark',
  },
  parameters: {
    backgrounds: { default: 'dark' },
  },
};

export const Light: Story = {
  args: {
    theme: 'light',
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};
