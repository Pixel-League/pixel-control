import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import { Button } from '../Button/Button';
import { Bracket } from './Bracket';
import { completedBracketFixture, inProgressBracketFixture } from './Bracket.fixtures';

type BracketPreset = 'completed' | 'in_progress';

const bracketPresets = {
  completed: completedBracketFixture,
  in_progress: inProgressBracketFixture,
} as const;

const meta: Meta<typeof Bracket> = {
  title: 'Components/Bracket',
  component: Bracket,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
    showConnectors: { control: 'boolean' },
    data: { control: false },
  },
  args: {
    data: completedBracketFixture,
    showConnectors: true,
  },
};

export default meta;
type Story = StoryObj<typeof Bracket>;

export const Default: Story = {};

export const ProgressionInPlay: Story = {
  args: {
    data: inProgressBracketFixture,
  },
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
    data: completedBracketFixture,
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};

export const PresentationPage: Story = {
  args: {
    theme: 'dark',
    showConnectors: true,
  },
  parameters: {
    layout: 'fullscreen',
  },
  render: function PresentationPageStory(args) {
    const [preset, setPreset] = useState<BracketPreset>('completed');
    const [connectorsEnabled, setConnectorsEnabled] = useState(args.showConnectors ?? true);

    const panelThemeClasses =
      args.theme === 'light'
        ? 'bg-nm-light border border-black/[0.08] shadow-nm-flat-l text-px-offblack'
        : 'bg-nm-dark border border-white/[0.08] shadow-nm-flat-d text-px-white';

    return (
      <div className="p-6 min-h-[760px] flex flex-col gap-6">
        <div className={`max-w-4xl p-5 ${panelThemeClasses}`}>
          <div className="space-y-4">
            <div>
              <p className="font-display text-xl font-bold uppercase tracking-display">
                Bracket Playground
              </p>
              <p className="font-body text-xs text-px-label mt-1">
                Switch between completed and in-progress datasets, and validate connector rendering
                stability while preserving neumorphic match cards.
              </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
              <Button
                size="sm"
                theme={args.theme}
                variant={preset === 'completed' ? 'primary' : 'ghost'}
                onClick={() => setPreset('completed')}
              >
                Completed Bracket
              </Button>
              <Button
                size="sm"
                theme={args.theme}
                variant={preset === 'in_progress' ? 'primary' : 'ghost'}
                onClick={() => setPreset('in_progress')}
              >
                In Progress
              </Button>
              <Button
                size="sm"
                theme={args.theme}
                variant={connectorsEnabled ? 'ghost-primary' : 'ghost'}
                onClick={() => setConnectorsEnabled((value) => !value)}
              >
                {connectorsEnabled ? 'Connectors: On' : 'Connectors: Off'}
              </Button>
            </div>
          </div>
        </div>

        <Bracket
          {...args}
          theme={args.theme}
          data={bracketPresets[preset]}
          showConnectors={connectorsEnabled}
        />
      </div>
    );
  },
};
