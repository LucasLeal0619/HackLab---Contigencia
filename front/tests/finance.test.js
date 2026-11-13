import { describe, expect, it } from 'vitest'
import { calculateTotalCents, formatMoney, formatQuantity } from '../src/js/pages/finance'

describe('finance amount helpers', () => {
  it('calculates quantity times unit price in cents without binary rounding errors', () => {
    expect(calculateTotalCents('10', '5')).toBe(5000n)
    expect(calculateTotalCents('4', '6.445')).toBe(2578n)
    expect(calculateTotalCents('1', '1.235')).toBe(124n)
  })

  it('keeps an undefined quote distinct from a zero-value item', () => {
    expect(calculateTotalCents('3', '')).toBeNull()
    expect(calculateTotalCents('3', '0')).toBe(0n)
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney('0.00')).toBe('R$ 0,00')
    expect(formatMoney('1.235')).toBe('R$ 1,24')
    expect(formatQuantity('10.0000')).toBe('10')
  })
})
