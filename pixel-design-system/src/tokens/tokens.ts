export const colors = {
  primary: '#2C12D9',
  primaryLight: '#4A35E0',
  primaryDark: '#1E0C96',
  primary30: 'rgba(44, 18, 217, 0.3)',
  dark: '#111111',
  offblack: '#14142B',
  white: '#FFFFFF',
  offwhite: '#FCFCFC',
  input: '#EFF0F6',
  label: '#7B7FA0',
  line: '#D9DBE9',
  error: '#E02020',
  success: '#00C853',
  warning: '#FFB020',
  nmDark: '#121212',
  nmDarkSurface: '#1C1B1F',
  nmLight: '#F0F2F5',
  nmLightSurface: '#E6E4EB',
} as const;

export type TypographyFamily = 'display' | 'body';
export type TypographyTransform = 'none' | 'uppercase';

export interface TypographyStyleToken {
  variable: `--px-type-${string}`;
  family: TypographyFamily;
  size: string;
  weight: number;
  lineHeight: string;
  letterSpacing: string;
  textTransform: TypographyTransform;
}

function typographyToken(
  variable: `--px-type-${string}`,
  family: TypographyFamily,
  size: string,
  weight: number,
  lineHeight: string,
  letterSpacing: string,
  textTransform: TypographyTransform = 'none',
): TypographyStyleToken {
  return {
    variable,
    family,
    size,
    weight,
    lineHeight,
    letterSpacing,
    textTransform,
  };
}

const displayTypography = {
  huge: typographyToken('--px-type-display-huge', 'display', '64px', 700, '100px', '1px', 'uppercase'),
  large: typographyToken('--px-type-display-large', 'display', '56px', 700, '100px', '1px', 'uppercase'),
  medium: typographyToken('--px-type-display-medium', 'display', '48px', 700, '100px', '1px', 'uppercase'),
  small: typographyToken('--px-type-display-small', 'display', '36px', 700, '100px', '1px', 'uppercase'),
  xSmall: typographyToken('--px-type-display-x-small', 'display', '24px', 700, '100px', '1px', 'uppercase'),
} as const;

const textTypography = {
  large: typographyToken('--px-type-text-large', 'display', '32px', 300, '32px', '0.05em'),
  medium: typographyToken('--px-type-text-medium', 'display', '24px', 300, '32px', '0.05em'),
  small: typographyToken('--px-type-text-small', 'display', '20px', 300, '24px', '0.05em'),
  xSmall: typographyToken('--px-type-text-x-small', 'display', '16px', 300, '22px', '0.05em'),
} as const;

const bodyTypography = {
  regular: typographyToken('--px-type-body-regular', 'body', '16px', 400, '24px', '0px'),
  small: typographyToken('--px-type-body-small', 'body', '14px', 500, '22px', '0px'),
  xSmall: typographyToken('--px-type-body-x-small', 'body', '12px', 400, '18px', '0px'),
  linkLarge: typographyToken('--px-type-body-link-large', 'body', '20px', 600, '32px', '0.75px'),
} as const;

const uiTypography = {
  label: typographyToken('--px-type-ui-label', 'body', '14px', 500, '22px', '0.75px'),
  labelStrong: typographyToken('--px-type-ui-label-strong', 'body', '14px', 600, '22px', '0.75px'),
  helper: typographyToken('--px-type-ui-helper', 'body', '12px', 400, '18px', '0px'),
  buttonSm: typographyToken('--px-type-ui-button-sm', 'body', '12px', 600, '16px', '0.75px', 'uppercase'),
  buttonMd: typographyToken('--px-type-ui-button-md', 'body', '14px', 600, '20px', '0.75px', 'uppercase'),
  buttonLg: typographyToken('--px-type-ui-button-lg', 'body', '16px', 600, '24px', '0.75px', 'uppercase'),
  overline: typographyToken('--px-type-ui-overline', 'body', '12px', 600, '16px', '1px', 'uppercase'),
} as const;

export const typography = {
  fontFamilies: {
    display: "'Plus Jakarta Sans', sans-serif",
    body: "'Poppins', sans-serif",
  },
  letterSpacing: {
    display: '1px',
    body: '0.75px',
    text: '0.05em',
    overline: '1px',
    none: '0px',
  },
  display: displayTypography,
  text: textTypography,
  body: bodyTypography,
  ui: uiTypography,
  semantic: {
    hero: displayTypography.huge,
    pageTitle: displayTypography.large,
    sectionTitle: displayTypography.medium,
    componentTitle: displayTypography.xSmall,
    editorialLead: textTypography.large,
    editorialBody: textTypography.medium,
    editorialCaption: textTypography.xSmall,
    bodyDefault: bodyTypography.regular,
    bodySmall: bodyTypography.small,
    label: uiTypography.label,
    helper: uiTypography.helper,
    button: uiTypography.buttonMd,
    overline: uiTypography.overline,
    linkLarge: bodyTypography.linkLarge,
  },
} as const;

function variableValueForStyle(style: TypographyStyleToken, fontFamilies: typeof typography.fontFamilies) {
  return {
    [`${style.variable}-font-family`]: fontFamilies[style.family],
    [`${style.variable}-size`]: style.size,
    [`${style.variable}-weight`]: String(style.weight),
    [`${style.variable}-line-height`]: style.lineHeight,
    [`${style.variable}-letter-spacing`]: style.letterSpacing,
    [`${style.variable}-text-transform`]: style.textTransform,
  } as const;
}

const typographyStyleGroups = [
  ...Object.values(displayTypography),
  ...Object.values(textTypography),
  ...Object.values(bodyTypography),
  ...Object.values(uiTypography),
];

export const typographyVariables = typographyStyleGroups.reduce<Record<string, string>>(
  (acc, style) => ({
    ...acc,
    ...variableValueForStyle(style, typography.fontFamilies),
  }),
  {},
);

function toKebabCase(value: string): string {
  return value.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
}

export const radii = {
  none: '0px',
  full: '9999px',
} as const;

export const shadows = {
  neumorphic: {
    raisedDark: '2px 2px 10px #000000, -2px -2px 10px #2A2A2A',
    insetDark: 'inset 5px 5px 10px #000000, inset -5px -5px 10px #2A2A2A',
    flatDark: '2px 2px 5px #000000, -2px -2px 5px #2A2A2A',
    buttonDark: '2px 2px 10px #000000, -2px -2px 10px #2A2A2A',
    raisedLight: '2px 2px 10px #CDD5E0, -2px -2px 10px #FFFFFF',
    insetLight: 'inset 5px 5px 10px #CDD5E0, inset -5px -5px 10px #FFFFFF',
    flatLight: '2px 2px 5px #CDD5E0, -2px -2px 5px #FFFFFF',
    buttonLight: '2px 2px 10px #CDD5E0, -2px -2px 10px #FFFFFF',
  },
  focus: {
    primaryDark:
      'inset 5px 5px 10px #000000, inset -5px -5px 10px #2A2A2A, 0 0 0 3px #2C12D9',
    errorDark:
      'inset 5px 5px 10px #000000, inset -5px -5px 10px #2A2A2A, 0 0 0 3px #E02020',
    successDark:
      'inset 5px 5px 10px #000000, inset -5px -5px 10px #2A2A2A, 0 0 0 3px #00C853',
    primaryLight:
      'inset 5px 5px 10px #CDD5E0, inset -5px -5px 10px #FFFFFF, 0 0 0 3px #2C12D9',
    errorLight:
      'inset 5px 5px 10px #CDD5E0, inset -5px -5px 10px #FFFFFF, 0 0 0 3px #E02020',
    successLight:
      'inset 5px 5px 10px #CDD5E0, inset -5px -5px 10px #FFFFFF, 0 0 0 3px #00C853',
  },
} as const;

export const shadowVariables = {
  ...Object.entries(shadows.neumorphic).reduce<Record<string, string>>(
    (acc, [token, value]) => ({
      ...acc,
      [`--px-shadow-${toKebabCase(token)}`]: value,
    }),
    {},
  ),
  ...Object.entries(shadows.focus).reduce<Record<string, string>>(
    (acc, [token, value]) => ({
      ...acc,
      [`--px-shadow-focus-${toKebabCase(token)}`]: value,
    }),
    {},
  ),
};

export const spacing = {
  0: '0px',
  1: '4px',
  2: '8px',
  3: '12px',
  4: '16px',
  5: '20px',
  6: '24px',
  8: '32px',
  10: '40px',
  12: '48px',
  16: '64px',
} as const;

export const spacingVariables = Object.entries(spacing).reduce<Record<string, string>>(
  (acc, [token, value]) => ({
    ...acc,
    [`--px-space-${token}`]: value,
  }),
  {},
);

export const radiusVariables = Object.entries(radii).reduce<Record<string, string>>(
  (acc, [token, value]) => ({
    ...acc,
    [`--px-radius-${token}`]: value,
  }),
  {},
);

export const layoutVariables = {
  ...spacingVariables,
  ...radiusVariables,
  ...shadowVariables,
};

export const semanticStates = {
  info: {
    accent: colors.primary,
  },
  success: {
    accent: colors.success,
  },
  warning: {
    accent: colors.warning,
  },
  error: {
    accent: colors.error,
  },
} as const;
