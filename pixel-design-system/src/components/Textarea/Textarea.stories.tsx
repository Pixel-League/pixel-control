import type { Meta, StoryObj } from '@storybook/react';
import { Textarea } from './Textarea';

const meta: Meta<typeof Textarea> = {
  title: 'Components/Textarea',
  component: Textarea,
  argTypes: {
    state: { control: 'select', options: ['default', 'error', 'success'] },
    theme: { control: 'select', options: ['dark', 'light'] },
    disabled: { control: 'boolean' },
  },
  args: {
    label: 'Match Notes',
    placeholder: 'Enter match notes...',
    helperText: 'Markdown is supported',
  },
};

export default meta;
type Story = StoryObj<typeof Textarea>;

export const Default: Story = {};

export const Error: Story = {
  args: {
    state: 'error',
    defaultValue: 'Missing summary',
    helperText: 'Please write at least 30 characters',
  },
};

export const Success: Story = {
  args: {
    state: 'success',
    defaultValue: 'Everything is documented.',
    helperText: 'Looks good',
  },
};

export const StateMatrix: Story = {
  render: (args) => (
    <div className="grid grid-cols-1 gap-4 max-w-md">
      <Textarea {...args} label="Default" />
      <Textarea {...args} label="Focused" autoFocus defaultValue="Focus ring" />
      <Textarea {...args} label="Error" state="error" helperText="Please provide details" defaultValue="Too short" />
      <Textarea {...args} label="Success" state="success" helperText="Saved" defaultValue="Validated text" />
      <Textarea {...args} label="Disabled" disabled defaultValue="Read-only note" />
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
