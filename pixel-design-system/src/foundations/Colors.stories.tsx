import type { Meta, StoryObj } from '@storybook/react';
import { useThemeOptional, type Theme } from '@/context/ThemeContext';
import { cn } from '@/utils/cn';
import { getTailwindPalette, type PaletteItem } from './tailwindPalette';

interface ColorPaletteBoardProps {
  theme: Theme;
}

function ColorPaletteBoard({ theme }: ColorPaletteBoardProps) {
  const resolvedTheme = useThemeOptional(theme);
  const palette = getTailwindPalette();
  const isDark = resolvedTheme === 'dark';

  return (
    <div className="space-y-6">
      <p className="font-body text-sm text-px-label">
        Palette auto-generated from `tailwind.config.ts` (`theme.colors` + `theme.extend.colors`).
      </p>

      <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        {palette.map((color) => (
          <ColorSwatch key={color.name} color={color} isDark={isDark} />
        ))}
      </div>
    </div>
  );
}

interface ColorSwatchProps {
  color: PaletteItem;
  isDark: boolean;
}

function ColorSwatch({ color, isDark }: ColorSwatchProps) {
  return (
    <div className={cn(isDark ? 'bg-nm-dark' : 'bg-nm-light')}>
      <div
        className={cn(
          'w-full h-20',
          isDark ? 'shadow-nm-raised-d border border-white/[0.08]' : 'shadow-nm-raised-l border border-black/[0.08]',
        )}
        style={{ backgroundColor: color.value }}
      />

      <div className="pt-2">
        <p className={cn('font-body text-xs font-semibold', isDark ? 'text-px-white' : 'text-px-offblack')}>
          {color.name}
        </p>
        <p className="font-body text-xs text-px-label break-all">{color.value}</p>
      </div>
    </div>
  );
}

const meta: Meta<typeof ColorPaletteBoard> = {
  title: 'Foundations/Colors',
  component: ColorPaletteBoard,
  parameters: {
    layout: 'padded',
  },
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    theme: 'dark',
  },
};

export default meta;
type Story = StoryObj<typeof ColorPaletteBoard>;

export const DarkPalette: Story = {
  args: {
    theme: 'dark',
  },
  parameters: {
    backgrounds: { default: 'dark' },
  },
};

export const LightPalette: Story = {
  args: {
    theme: 'light',
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};
