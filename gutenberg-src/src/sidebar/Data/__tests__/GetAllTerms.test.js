import React from 'react';
import { render } from '@testing-library/react';
import { GetAllTerms } from '../GetAllTerms';

beforeEach(() => {
    global.fetch = jest.fn();
});

afterEach(() => {
    jest.restoreAllMocks();
});

describe('GetAllTerms', () => {
    it('renders nothing', () => {
        global.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve([]) });
        const { container } = render(<GetAllTerms onTermsChange={jest.fn()} currentTerms={[]} />);
        expect(container.firstChild).toBeFalsy();
    });

    it('fetches terms on mount when currentTerms is empty', async () => {
        const terms = [{ id: 1, name: 'Category A' }];
        global.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve(terms) });
        const onTermsChange = jest.fn();

        render(<GetAllTerms onTermsChange={onTermsChange} currentTerms={[]} />);
        await new Promise(r => setTimeout(r, 0));

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toContain('linkate_gutenberg_hierarchical_terms_json');
        expect(global.fetch.mock.calls[0][1].method).toBe('GET');
    });

    it('skips fetch when currentTerms already has items', async () => {
        global.fetch = jest.fn();
        render(<GetAllTerms onTermsChange={jest.fn()} currentTerms={[{ id: 1 }]} />);
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('calls onTermsChange with inProgress:true then with response data', async () => {
        const terms = [{ id: 1 }];
        global.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve(terms) });
        const onTermsChange = jest.fn();

        render(<GetAllTerms onTermsChange={onTermsChange} currentTerms={[]} />);

        expect(onTermsChange).toHaveBeenCalledWith({ inProgress: true });

        await new Promise(r => setTimeout(r, 0));

        expect(onTermsChange).toHaveBeenLastCalledWith(terms);
    });

    it('calls onTermsChange with isError:true on failure', async () => {
        global.fetch = jest.fn().mockRejectedValue(new Error('fail'));
        const onTermsChange = jest.fn();
        jest.spyOn(console, 'error').mockImplementation(() => {});

        render(<GetAllTerms onTermsChange={onTermsChange} currentTerms={[]} />);
        await new Promise(r => setTimeout(r, 0));

        expect(onTermsChange).toHaveBeenLastCalledWith({ isError: true });
    });
});
