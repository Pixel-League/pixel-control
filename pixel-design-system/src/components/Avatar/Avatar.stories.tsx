import type { Meta, StoryObj } from '@storybook/react';
import { Avatar } from './Avatar';

const meta: Meta<typeof Avatar> = {
  title: 'Components/Avatar',
  component: Avatar,
  argTypes: {
    size: { control: 'select', options: ['sm', 'md', 'lg', 'xl'] },
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    alt: 'Player One',
  },
};

export default meta;
type Story = StoryObj<typeof Avatar>;

export const Default: Story = {};

export const WithImage: Story = {
  args: {
    src: 'https://api.dicebear.com/7.x/identicon/svg?seed=pixel',
    alt: 'Pixel',
  },
};

export const WithInitials: Story = {
  args: {
    initials: 'PX',
    alt: 'Pixel',
  },
};

export const SizeMatrix: Story = {
  render: (args) => (
    <div className="flex items-end gap-4">
      <Avatar {...args} size="sm" initials="S" alt="Small" />
      <Avatar {...args} size="md" initials="M" alt="Medium" />
      <Avatar {...args} size="lg" initials="L" alt="Large" />
      <Avatar {...args} size="xl" initials="XL" alt="Extra Large" />
    </div>
  ),
};

export const Fallback: Story = {
  args: {
    src: 'https://broken.invalid/no-image.png',
    alt: 'Broken Image',
  },
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
    initials: 'PX',
  },
  parameters: { backgrounds: { default: 'light' } },
};
