import type { Meta, StoryObj } from '@storybook/react';
import { Progress } from './Progress';

const meta: Meta<typeof Progress> = {
  title: 'Components/Progress',
  component: Progress,
  argTypes: {
    variant: { control: 'select', options: ['bar', 'spinner'] },
    size: { control: 'select', options: ['sm', 'md', 'lg'] },
    theme: { control: 'select', options: ['dark', 'light'] },
    value: { control: { type: 'range', min: 0, max: 100 } },
  },
  args: {
    value: 65,
    variant: 'bar',
    size: 'md',
  },
};

export default meta;
type Story = StoryObj<typeof Progress>;

export const Default: Story = {};

export const WithLabel: Story = {
  args: { label: 'Upload progress', value: 42 },
};

export const Indeterminate: Story = {
  args: { value: undefined, label: 'Processing' },
};

export const Spinner: Story = {
  args: { variant: 'spinner', label: 'Loading' },
};

export const SizeMatrix: Story = {
  render: (args) => (
    <div className="space-y-4 max-w-md">
      <Progress {...args} size="sm" value={30} label="Small" />
      <Progress {...args} size="md" value={55} label="Medium" />
      <Progress {...args} size="lg" value={80} label="Large" />
    </div>
  ),
};

export const SpinnerSizes: Story = {
  render: (args) => (
    <div className="flex items-center gap-6">
      <Progress {...args} variant="spinner" size="sm" />
      <Progress {...args} variant="spinner" size="md" />
      <Progress {...args} variant="spinner" size="lg" />
    </div>
  ),
};

export const LightTheme: Story = {
  args: { theme: 'light', value: 70, label: 'Progress' },
  parameters: { backgrounds: { default: 'light' } },
};
