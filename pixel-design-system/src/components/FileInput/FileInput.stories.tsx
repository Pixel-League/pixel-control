import type { Meta, StoryObj } from '@storybook/react';
import { FileInput } from './FileInput';

const meta: Meta<typeof FileInput> = {
  title: 'Components/FileInput',
  component: FileInput,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
    disabled: { control: 'boolean' },
    multiple: { control: 'boolean' },
  },
  args: {
    label: 'File Upload',
    hint: 'PNG, JPG up to 10MB',
  },
};

export default meta;
type Story = StoryObj<typeof FileInput>;

export const Default: Story = {};

export const WithAccept: Story = {
  args: {
    accept: 'image/*',
    hint: 'Images only',
  },
};

export const Multiple: Story = {
  args: { multiple: true, hint: 'Select multiple files' },
};

export const Disabled: Story = {
  args: { disabled: true },
};

export const LightTheme: Story = {
  args: { theme: 'light' },
  parameters: { backgrounds: { default: 'light' } },
};
