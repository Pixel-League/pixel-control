import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import { Tooltip, type TooltipPosition } from './Tooltip';
import { Button } from '../Button/Button';

const meta: Meta<typeof Tooltip> = {
  title: 'Components/Tooltip',
  component: Tooltip,
  argTypes: {
    position: { control: 'select', options: ['top', 'bottom', 'left', 'right'] },
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    content: 'More info',
    position: 'top',
  },
};

export default meta;
type Story = StoryObj<typeof Tooltip>;

export const Default: Story = {
  render: (args) => (
    <div className="p-16 flex items-center justify-center">
      <Tooltip {...args}>
        <Button variant="ghost" size="sm">Hover me</Button>
      </Tooltip>
    </div>
  ),
};

export const Positions: Story = {
  render: (args) => (
    <div className="p-24 flex items-center justify-center gap-8">
      <Tooltip {...args} position="top" content="Top">
        <Button variant="ghost" size="sm">Top</Button>
      </Tooltip>
      <Tooltip {...args} position="bottom" content="Bottom">
        <Button variant="ghost" size="sm">Bottom</Button>
      </Tooltip>
      <Tooltip {...args} position="left" content="Left">
        <Button variant="ghost" size="sm">Left</Button>
      </Tooltip>
      <Tooltip {...args} position="right" content="Right">
        <Button variant="ghost" size="sm">Right</Button>
      </Tooltip>
    </div>
  ),
};

export const PresentationPage: Story = {
  args: { theme: 'dark' },
  render: function PresentationPageStory(args) {
    const [position, setPosition] = useState<TooltipPosition>('top');
    const [delay, setDelay] = useState(200);

    const panelThemeClasses =
      args.theme === 'light'
        ? 'bg-nm-light border border-black/[0.08] shadow-nm-flat-l text-px-offblack'
        : 'bg-nm-dark border border-white/[0.08] shadow-nm-flat-d text-px-white';

    return (
      <div className="p-6 min-h-[420px] flex flex-col gap-6">
        <div className={`max-w-3xl p-5 ${panelThemeClasses}`}>
          <div className="space-y-4">
            <div>
              <p className="font-display text-xl font-bold uppercase tracking-display">
                Tooltip Playground
              </p>
              <p className="font-body text-xs text-px-label mt-1">
                Hover or focus the trigger button after choosing a position and reveal delay.
              </p>
            </div>

            <div className="flex flex-wrap gap-2 items-center">
              {(['top', 'right', 'bottom', 'left'] as const).map((option) => (
                <Button
                  key={option}
                  theme={args.theme}
                  size="sm"
                  variant={position === option ? 'primary' : 'ghost'}
                  onClick={() => setPosition(option)}
                >
                  {option.toUpperCase()}
                </Button>
              ))}
            </div>

            <div className="flex flex-wrap gap-2 items-center">
              {[0, 200, 500].map((delayOption) => (
                <Button
                  key={delayOption}
                  theme={args.theme}
                  size="sm"
                  variant={delay === delayOption ? 'ghost-primary' : 'ghost'}
                  onClick={() => setDelay(delayOption)}
                >
                  Delay {delayOption}ms
                </Button>
              ))}
            </div>
          </div>
        </div>

        <div className="flex-1 min-h-[220px] flex items-center justify-center">
          <Tooltip
            {...args}
            content={`Tooltip on ${position} (${delay}ms)`}
            position={position}
            delay={delay}
          >
            <Button variant="ghost" size="md" theme={args.theme}>
              Hover or Focus Me
            </Button>
          </Tooltip>
        </div>
      </div>
    );
  },
};

export const LightTheme: Story = {
  args: { theme: 'light' },
  render: (args) => (
    <div className="p-16 flex items-center justify-center">
      <Tooltip {...args}>
        <Button variant="ghost" size="sm" theme="light">Hover me</Button>
      </Tooltip>
    </div>
  ),
  parameters: { backgrounds: { default: 'light' } },
};
