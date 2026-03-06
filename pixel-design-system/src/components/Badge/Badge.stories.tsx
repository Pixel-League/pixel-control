import type { Meta, StoryObj } from '@storybook/react';
import { Badge } from './Badge';

const meta: Meta<typeof Badge> = {
  title: 'Components/Badge',
  component: Badge,
  argTypes: {
    variant: { control: 'select', options: ['primary', 'success', 'error', 'warning', 'neutral', 'inactive'] },
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    variant: 'primary',
    children: 'Live',
  },
};

export default meta;
type Story = StoryObj<typeof Badge>;

export const Default: Story = {};

export const VariantMatrix: Story = {
  render: (args) => (
    <div className="flex flex-wrap gap-3">
      <Badge {...args} variant="primary">Live</Badge>
      <Badge {...args} variant="success">Winner</Badge>
      <Badge {...args} variant="error">Eliminated</Badge>
      <Badge {...args} variant="warning">Pending</Badge>
      <Badge {...args} variant="neutral">Draft</Badge>
      <Badge {...args} variant="inactive">Inactive</Badge>
    </div>
  ),
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};
