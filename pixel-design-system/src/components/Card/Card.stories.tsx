import type { Meta, StoryObj } from '@storybook/react';
import { Badge } from '@/components/Badge/Badge';
import { Card } from './Card';

const meta: Meta<typeof Card> = {
  title: 'Components/Card',
  component: Card,
  argTypes: {
    tone: { control: 'select', options: ['primary', 'dark', 'surface'] },
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    title: 'OWNED vs CRYSTAL',
    description: 'Semi-final - Best of 5',
    badge: <Badge variant="primary">Week 4</Badge>,
  },
};

export default meta;
type Story = StoryObj<typeof Card>;

export const Default: Story = {};

export const Matrix: Story = {
  render: (args) => (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
      <Card {...args} tone="primary" />
      <Card
        {...args}
        tone="dark"
        badge={<Badge variant="success">Completed</Badge>}
        title="EDEN vs HYPSTER"
        description="Quarter-final - 3-2"
      />
      <Card
        {...args}
        tone="surface"
        muted
        badge={<Badge variant="inactive">TBD</Badge>}
        title="??? vs ???"
        description="Final - TBD"
      />
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
