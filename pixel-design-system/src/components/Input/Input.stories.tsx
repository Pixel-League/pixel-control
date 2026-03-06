import type { Meta, StoryObj } from '@storybook/react';
import { Input } from './Input';

const meta: Meta<typeof Input> = {
  title: 'Components/Input',
  component: Input,
  argTypes: {
    state: { control: 'select', options: ['default', 'error', 'success'] },
    theme: { control: 'select', options: ['dark', 'light'] },
    disabled: { control: 'boolean' },
  },
  args: {
    label: 'Team Name',
    placeholder: 'OWNED',
    helperText: 'Used in public bracket',
  },
};

export default meta;
type Story = StoryObj<typeof Input>;

export const Default: Story = {};

export const Error: Story = {
  args: {
    state: 'error',
    value: 'Invalid name',
    helperText: 'This value is not allowed',
  },
};

export const Success: Story = {
  args: {
    state: 'success',
    value: 'VALID ENTRY',
    helperText: 'Looks good!',
  },
};

export const StateMatrix: Story = {
  render: (args) => (
    <div className="grid grid-cols-1 gap-4 max-w-md">
      <Input {...args} label="Default" placeholder="Placeholder text" />
      <Input {...args} label="Focused" defaultValue="Focus ring" autoFocus />
      <Input {...args} label="Error" state="error" defaultValue="Invalid data" helperText="This field is required" />
      <Input {...args} label="Success" state="success" defaultValue="Valid data" helperText="Looks good" />
      <Input {...args} label="Disabled" defaultValue="Disabled" disabled />
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
