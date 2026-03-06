import type { Meta, StoryObj } from '@storybook/react';
import { Skeleton } from './Skeleton';

const meta: Meta<typeof Skeleton> = {
  title: 'Components/Skeleton',
  component: Skeleton,
  argTypes: {
    variant: { control: 'select', options: ['text', 'rectangular', 'circular'] },
    theme: { control: 'select', options: ['dark', 'light'] },
    lines: { control: { type: 'range', min: 1, max: 6 } },
  },
};

export default meta;
type Story = StoryObj<typeof Skeleton>;

export const Default: Story = {};

export const TextMultiLine: Story = {
  args: { variant: 'text', lines: 3 },
};

export const Rectangular: Story = {
  args: { variant: 'rectangular', height: 128 },
};

export const Circular: Story = {
  args: { variant: 'circular', width: 48, height: 48 },
};

export const CardSkeleton: Story = {
  render: (args) => (
    <div className="max-w-sm space-y-4">
      <Skeleton {...args} variant="rectangular" height={128} />
      <div className="px-4 space-y-3">
        <Skeleton {...args} variant="text" width="40%" />
        <Skeleton {...args} variant="text" lines={2} />
      </div>
    </div>
  ),
};

export const LightTheme: Story = {
  args: { theme: 'light', variant: 'text', lines: 3 },
  parameters: { backgrounds: { default: 'light' } },
};
