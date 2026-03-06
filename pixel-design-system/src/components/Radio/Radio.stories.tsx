import type { Meta, StoryObj } from '@storybook/react';
import { Radio } from './Radio';

const meta: Meta<typeof Radio> = {
  title: 'Components/Radio',
  component: Radio,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
    disabled: { control: 'boolean' },
  },
  args: {
    name: 'team',
  },
};

export default meta;
type Story = StoryObj<typeof Radio>;

export const Default: Story = {
  render: (args) => (
    <div className="grid gap-3">
      <Radio {...args} label="Team A" defaultChecked />
      <Radio {...args} label="Team B" />
    </div>
  ),
};

export const StateMatrix: Story = {
  render: (args) => (
    <div className="grid gap-3">
      <Radio {...args} label="Unchecked" />
      <Radio {...args} label="Checked" defaultChecked />
      <Radio {...args} label="Disabled" disabled />
      <Radio {...args} label="Disabled checked" disabled defaultChecked />
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
