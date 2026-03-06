import type { Meta, StoryObj } from '@storybook/react';
import { Select } from './Select';

const teamOptions = [
  { value: 'owned', label: 'OWNED' },
  { value: 'crystal', label: 'CRYSTAL' },
  { value: 'eden', label: 'EDEN' },
];

const meta: Meta<typeof Select> = {
  title: 'Components/Select',
  component: Select,
  argTypes: {
    state: { control: 'select', options: ['default', 'error', 'success'] },
    theme: { control: 'select', options: ['dark', 'light'] },
    disabled: { control: 'boolean' },
  },
  args: {
    label: 'Team',
    options: teamOptions,
    placeholder: 'Select a team',
    helperText: 'Required for scheduling',
  },
};

export default meta;
type Story = StoryObj<typeof Select>;

export const Default: Story = {};

export const Error: Story = {
  args: {
    state: 'error',
    helperText: 'Please choose a team',
  },
};

export const Success: Story = {
  args: {
    state: 'success',
    defaultValue: 'owned',
    helperText: 'Selection saved',
  },
};

export const StateMatrix: Story = {
  render: (args) => (
    <div className="grid grid-cols-1 gap-4 max-w-md">
      <Select {...args} label="Default" />
      <Select {...args} label="Focused" autoFocus />
      <Select {...args} label="Error" state="error" helperText="Selection missing" />
      <Select {...args} label="Success" state="success" defaultValue="crystal" helperText="Looks good" />
      <Select {...args} label="Disabled" disabled defaultValue="eden" />
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
