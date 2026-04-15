package producer

import (
	"reflect"
	"testing"
)

func TestUniquePartitionsDeduplicatesWhilePreservingOrder(t *testing.T) {
	partitions := []string{"delete-listing-0", "delete-listing-0", "", "delete-listing-1", "delete-listing-1"}

	got := uniquePartitions(partitions)
	want := []string{"delete-listing-0", "delete-listing-1"}

	if !reflect.DeepEqual(got, want) {
		t.Fatalf("expected %v, got %v", want, got)
	}
}
