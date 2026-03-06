import type { Meta, StoryObj } from '@storybook/react';
import { Divider } from './Divider';

const meta: Meta<typeof Divider> = {
  title: 'Components/Divider',
  component: Divider,
  argTypes: {
    orientation: { control: 'select', options: ['horizontal', 'vertical'] },
    theme: { control: 'select', options: ['dark', 'light'] },
  },
};

export default meta;
type Story = StoryObj<typeof Divider>;

export const Default: Story = {};

export const WithLabel: Story = {
  args: { label: 'or' },
};

export const Vertical: Story = {
  render: (args) => (
    <div className="flex items-center gap-4 h-12">
      <span className="font-body text-sm text-px-label">Left</span>
      <Divider {...args} orientation="vertical" />
      <span className="font-body text-sm text-px-label">Right</span>
    </div>
  ),
};

export const StateMatrix: Story = {
  render: (args) => (
    <div className="space-y-6 max-w-md">
      <div>
        <p className="font-body text-xs text-px-label mb-2 uppercase">Horizontal</p>
        <Divider {...args} />
      </div>
      <div>
        <p className="font-body text-xs text-px-label mb-2 uppercase">With label</p>
        <Divider {...args} label="section" />
      </div>
      <div>
        <p className="font-body text-xs text-px-label mb-2 uppercase">Vertical</p>
        <div className="flex items-center gap-3 h-8">
          <span className="font-body text-xs text-px-label">A</span>
          <Divider {...args} orientation="vertical" />
          <span className="font-body text-xs text-px-label">B</span>
        </div>
      </div>
    </div>
  ),
};

export const LightTheme: Story = {
  args: { theme: 'light' },
  parameters: { backgrounds: { default: 'light' } },
};
