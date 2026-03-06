import type { Meta, StoryObj } from '@storybook/react';
import { Checkbox } from './Checkbox';

const meta: Meta<typeof Checkbox> = {
  title: 'Components/Checkbox',
  component: Checkbox,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
    disabled: { control: 'boolean' },
    checked: { control: 'boolean' },
  },
  args: {
    label: 'Active roster slot',
  },
};

export default meta;
type Story = StoryObj<typeof Checkbox>;

export const Default: Story = {};

export const Checked: Story = {
  args: {
    defaultChecked: true,
  },
};

export const StateMatrix: Story = {
  render: (args) => (
    <div className="grid gap-4">
      <Checkbox {...args} label="Default" />
      <Checkbox {...args} label="Checked" defaultChecked />
      <Checkbox {...args} label="Disabled" disabled />
      <Checkbox {...args} label="Disabled checked" disabled defaultChecked />
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
