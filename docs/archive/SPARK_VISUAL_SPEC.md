# Spark Visual Specification

## Document Purpose
This document captures the exact visual design specifications from the original GitHub Spark application to ensure pixel-perfect migration to Laravel 11 + Inertia React.

## Source Analysis
**Original Spark Files**: `/src` directory
**CSS**: `src/index.css` with oklch() color definitions
**Layout**: `src/components/Layout.tsx`
**Navigation**: 9 main menu items + Settings

---

## Layout Structure

### Sidebar
- **Width**: 256px (w-64)
- **Position**: Fixed left
- **Background**: `bg-card` (CSS variable)
- **Border**: Right border (`border-r`)
- **Mobile**: Slide-out menu with overlay

### Main Content Area
- **Margin Left**: 256px (ml-64) to offset sidebar
- **Background**: `bg-background` (CSS variable)
- **Padding**: 2rem (p-8)

---

## Color System

### Primary System (oklch from Spark)
- Background: oklch(0.99 0 0)
- Card: oklch(0.98 0.005 250)
- Primary: oklch(0.45 0.15 250) - Blue
- Muted: oklch(0.88 0.005 250)

### Menu Colors
- Active: bg-primary, text-primary-foreground
- Hover: hover:bg-muted or hover:bg-neutral-3
- Default: text-muted-foreground

---

## Typography

- **Font**: Inter, -apple-system, BlinkMacSystemFont, sans-serif
- **H1**: text-2xl font-bold (24px)
- **H2**: text-xl font-semibold (20px)
- **Body**: text-base (16px)
- **Small**: text-sm (14px)
- **Stats**: text-3xl font-bold (30px)

---

## Components

### Sidebar
- Width: 256px (w-64)
- 9 main menus + Settings
- Active state: bg-primary
- Icons: 20px, @phosphor-icons/react

### Stats Cards
- Grid: 3 columns
- Gap: 1.5rem
- Padding: p-6
- Icons: 24px with colored backgrounds

### List Items
- Padding: p-4
- Background: bg-gray-50
- Hover: hover:bg-gray-100
- Rounded: rounded-lg

---

**Last Updated**: 2026-01-30
