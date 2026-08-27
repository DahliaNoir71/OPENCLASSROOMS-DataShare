const SIZE_UNITS = ['o', 'Ko', 'Mo', 'Go']

export function formatFileSize(bytes: number): string {
  let value = bytes
  let unit = 0

  while (value >= 1024 && unit < SIZE_UNITS.length - 1) {
    value /= 1024
    unit += 1
  }

  // Octets et kilo-octets restent entiers ; au-delà, une décimale suffit à
  // situer la taille sans donner une fausse précision.
  const rounded = unit < 2 ? Math.round(value) : Math.round(value * 10) / 10

  return `${rounded.toString().replace('.', ',')} ${SIZE_UNITS[unit]}`
}
