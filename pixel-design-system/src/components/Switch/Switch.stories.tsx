import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import { Switch } from './Switch';

const meta: Meta<typeof Switch> = {
  title: 'Components/Switch',
  component: Switch,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
    disabled: { control: 'boolean' },
  },
  args: {
    label: 'Enable notifications',
  },
};

export default meta;
type Story = StoryObj<typeof Switch>;

export const Default: Story = {
  render: (args) => {
    const [checked, setChecked] = useState(false);

    return <Switch {...args} checked={checked} onCheckedChange={setChecked} />;
  },
};

export const Checked: Story = {
  args: {
    checked: true,
  },
};

export const StateMatrix: Story = {
  render: (args) => (
    <div className="grid gap-4">
      <Switch {...args} defaultChecked={false} label="Off" />
      <Switch {...args} defaultChecked label="On" />
      <Switch {...args} disabled defaultChecked={false} label="Disabled off" />
      <Switch {...args} disabled defaultChecked label="Disabled on" />
    </div>
  ),
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
    defaultChecked: true,
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};
